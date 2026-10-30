<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use App\Models\User;
use App\Services\QuestionContactService;
use App\Services\SurveySubmissionService;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SurveyUnpublishTest extends SurveyTestCase
{
    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['school_id' => $this->school->id, 'is_admin' => true])->save();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        return $user;
    }

    private function contactQuestion(): array
    {
        return [
            'key' => 'activity', 'section' => 'all', 'type' => 'single', 'title' => 'Activity',
            'required' => false, 'allow_other' => false, 'exclusive_values' => [], 'condition' => null,
            'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']],
            'contact' => ['prompt' => 'Contact', 'purpose' => 'Activity coordination', 'trigger_values' => ['yes'],
                'fields' => [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]],
        ];
    }

    public function test_unpublish_preserves_answers_contacts_definition_history_and_publication_identity(): void
    {
        $this->admin();
        $this->survey->questions = [$this->contactQuestion()];
        $this->survey->save();
        $response = app(SurveySubmissionService::class)->submit($this->survey, $this->payload(['activity' => 'yes']));
        $contactPayload = ['contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes', 'fields' => ['email' => 'parent@example.com']];
        $contact = app(QuestionContactService::class)->submit($this->survey, 'activity', $contactPayload);
        $this->survey->questions = [];
        $this->survey->save();
        $before = $this->survey->fresh();
        $contactBytes = $contact->getRawOriginal();
        $responseBytes = $response->getRawOriginal();
        $answerBytes = $this->survey->questionAnswers()->firstOrFail()->getRawOriginal();

        $hidden = SurveyWorkflow::unpublish($this->survey);
        $this->assertSame('closed', $hidden->status);
        $this->assertNotNull($hidden->unpublished_at);
        $this->assertSame($before->slug, $hidden->slug);
        $this->assertSame($before->school_id, $hidden->school_id);
        $this->assertTrue($before->published_at->equalTo($hidden->published_at));
        $this->assertSame($before->questions, $hidden->questions);
        $this->assertSame($before->archived_questions, $hidden->archived_questions);
        $this->assertSame($contactBytes, $contact->fresh()->getRawOriginal());
        $this->assertSame($responseBytes, $response->fresh()->getRawOriginal());
        $this->assertSame($answerBytes, $this->survey->questionAnswers()->firstOrFail()->getRawOriginal());
        $this->assertFalse($hidden->isAcceptingResponses());
        $this->assertFalse(Survey::query()->publiclyVisible()->whereKey($hidden->id)->exists());
        $this->assertSame($response->id, app(SurveySubmissionService::class)->submit($hidden, $this->payload([], ['submission_id' => $response->id]))->id);
        $this->assertSame($contact->id, app(QuestionContactService::class)->submit($hidden, 'activity', $contactPayload)->id);
        $this->assertSame(1, $hidden->questionContacts()->count());
    }

    public function test_unpublished_survey_is_hidden_from_all_public_pages_and_submission_routes(): void
    {
        $this->admin();
        $this->survey->questions = [$this->contactQuestion()];
        $this->survey->save();
        $invitation = $this->invitation();
        $path = '/a/'.$this->survey->slug;
        $this->get($path)->assertOk();
        SurveyWorkflow::unpublish($this->survey);
        $this->get('/')->assertOk()->assertDontSee($path);
        foreach ([$path, $path.'/paldies', $path.'/session', $path.'/g/'.$invitation->token, $path.'/g/'.$invitation->token.'/session', $path.'/kontakti/activity', $path.'/kontakti/activity/paldies'] as $url) {
            $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        }
        foreach ([$path, $path.'/velreiz', $path.'/g/'.$invitation->token, $path.'/g/'.$invitation->token.'/velreiz', $path.'/kontakti/activity'] as $url) {
            $this->postJson($url, [])->assertNotFound();
        }
        $this->assertSame(0, $this->survey->responses()->count());
        $this->assertSame(0, $this->survey->questionContacts()->count());
    }

    public function test_stale_models_and_unsaved_forms_cannot_accept_new_answers_after_unpublishing(): void
    {
        $this->admin();
        $stale = $this->survey->fresh();
        SurveyWorkflow::unpublish($this->survey);
        try {
            app(SurveySubmissionService::class)->submit($stale, $this->payload());
            $this->fail('Hidden survey accepted a new answer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('survey', $exception->errors());
        }
        $stale->title = 'Updated wording';
        $stale->save();
        $this->assertNotNull($stale->unpublished_at);
        $this->assertSame('closed', $stale->status);
        $this->assertSame(0, $stale->responses()->count());
    }

    public function test_republishing_restores_the_same_public_link_and_original_publication_date(): void
    {
        $this->admin();
        $definition = json_decode(file_get_contents(database_path('seed-data/october-survey.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->survey->fill($definition)->save();
        $slug = $this->survey->slug;
        $publishedAt = $this->survey->published_at;
        SurveyWorkflow::unpublish($this->survey);
        $restored = SurveyWorkflow::publish($this->survey);
        $this->assertNull($restored->unpublished_at);
        $this->assertSame('open', $restored->status);
        $this->assertSame($slug, $restored->slug);
        $this->assertTrue($publishedAt->equalTo($restored->published_at));
        $this->get('/a/'.$slug)->assertOk();
    }

    public function test_unpublish_is_authorized_and_cannot_hide_an_unpublished_draft(): void
    {
        $this->admin();
        $other = $this->otherSurvey();
        try {
            SurveyWorkflow::unpublish($other);
            $this->fail('Foreign survey was unpublished.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $draft = Survey::create(['school_id' => $this->school->id, 'title' => 'Draft', 'questions' => [], 'sections' => []]);
        try {
            SurveyWorkflow::unpublish($draft);
            $this->fail('Draft was unpublished.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertNull($other->fresh()->unpublished_at);
        $this->assertNull($draft->fresh()->unpublished_at);
    }

    public function test_visible_admin_controls_unpublish_without_returning_the_survey_to_draft(): void
    {
        $this->admin();
        $this->get('/admin/aptaujas')->assertOk()->assertSee('Atvērt aptauju')->assertSee('Dalīties')->assertSee('Atcelt publicēšanu');
        $this->get('/admin/aptaujas/'.$this->survey->id.'/labot')->assertOk()->assertSee('data-survey-share', false)->assertSee('survey-share.js');
        Livewire::test(EditSurvey::class, ['record' => $this->survey->id])->callAction('unpublish')->assertHasNoActionErrors();
        $this->assertNotNull($this->survey->fresh()->unpublished_at);
        $this->get('/admin/aptaujas/'.$this->survey->id.'/labot')->assertOk()->assertSee('Publicēt vēlreiz')->assertDontSee('data-survey-share', false);
        $this->expectException(ValidationException::class);
        $this->survey->fresh()->update(['status' => 'draft']);
    }

    public function test_unpublished_stale_contact_form_cannot_insert_new_contact_values(): void
    {
        $this->admin();
        $this->survey->questions = [$this->contactQuestion()];
        $this->survey->save();
        $stale = $this->survey->fresh();
        SurveyWorkflow::unpublish($this->survey);
        try {
            app(QuestionContactService::class)->submit($stale, 'activity', ['contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes', 'fields' => ['email' => 'parent@example.com']]);
            $this->fail('Unpublished survey accepted a new contact.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('survey', $exception->errors());
        }
        $this->assertSame(0, $this->survey->questionContacts()->count());
    }
}
