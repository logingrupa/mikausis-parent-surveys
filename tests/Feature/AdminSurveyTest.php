<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Filament\Resources\Surveys\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\School;
use App\Models\Survey;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSurveyTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function school(): School
    {
        return School::create(['name' => 'Mikausis', 'slug' => 'school-'.Str::random(8)]);
    }

    private function admin(?School $school = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true, 'school_id' => ($school ?? $this->school())->id])->save();

        return $user;
    }

    private function definition(): array
    {
        return json_decode(file_get_contents(database_path('seed-data/october-survey.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function survey(School $school): Survey
    {
        return Survey::create([...$this->definition(), 'school_id' => $school->id]);
    }

    public function test_panel_requires_an_admin_with_an_existing_school_and_has_no_registration(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
        $this->get('/admin/register')->assertNotFound();
        $member = User::factory()->create();
        $this->actingAs($member)->get('/admin')->assertForbidden();
        $member->forceFill(['is_admin' => true])->save();
        $this->actingAs($member)->get('/admin')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Vecāku viedoklis. Kopīgi lēmumi.')->assertDontSee('filamentphp.com');
    }

    public function test_all_survey_pages_and_queries_are_scoped_to_the_current_school(): void
    {
        $ownSchool = $this->school();
        $own = $this->survey($ownSchool);
        $other = $this->survey($this->school());
        $other->update(['title' => 'Citas iestādes slepenā aptauja']);
        $this->actingAs($this->admin($ownSchool));
        $this->assertSame([$own->id], SurveyResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(SurveyResource::canEdit($other));
        $this->assertFalse(SurveyResource::canDelete($own));
        $this->get(SurveyResource::getUrl('index'))->assertOk()->assertSee($own->title)->assertDontSee($other->title);
        $this->get(SurveyResource::getUrl('edit', ['record' => $own]))->assertOk();
        $this->get(SurveyResource::getUrl('edit', ['record' => $other]))->assertNotFound();
        $this->get(SurveyResource::getUrl('report', ['record' => $other]))->assertNotFound();
        $this->get('/admin')->assertOk()->assertDontSee($other->title);
    }

    public function test_livewire_creation_ignores_forged_tenant_status_and_slug(): void
    {
        $admin = $this->admin();
        $foreign = $this->school();
        $this->actingAs($admin);
        Livewire::test(CreateSurvey::class)
            ->set('data', SurveyEditorData::fromDefinition($this->definition()))
            ->set('data.school_id', $foreign->id)
            ->set('data.status', 'open')
            ->set('data.slug', 'forged-slug')
            ->call('create')->assertHasNoFormErrors();
        $survey = Survey::firstOrFail();
        $this->assertSame($admin->school_id, $survey->school_id);
        $this->assertSame('draft', $survey->status);
        $this->assertNull($survey->published_at);
        $this->assertNotSame('forged-slug', $survey->slug);
        $this->assertSame($this->definition()['questions'], $survey->questions);
    }

    public function test_editing_draft_preserves_all_source_questions_sections_and_conditions(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->actingAs($this->admin($school));
        Livewire::test(EditSurvey::class, ['record' => $survey->id])
            ->call('save')->assertHasNoFormErrors();
        $survey->refresh();
        $this->assertSame($this->definition()['questions'], $survey->questions);
        $this->assertSame($this->definition()['sections'], $survey->sections);
    }

    public function test_builder_rejects_repeated_keys_missing_sections_and_forward_conditions(): void
    {
        $data = $this->definition();
        $data['questions'][1]['key'] = 'q1';
        $data['questions'][2]['section'] = 'missing';
        $data['questions'][0]['condition'] = ['question' => 'q14', 'values' => ['anything']];
        try {
            SurveyWorkflow::definition($data, true);
            $this->fail('Invalid builder definition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('questions.0.key', $exception->errors());
            $this->assertArrayHasKey('questions.2.section', $exception->errors());
            $this->assertArrayHasKey('questions.0.condition.question', $exception->errors());
        }
    }

    public function test_publishing_rejects_empty_surveys_and_published_editor_saves_valid_content_with_server_owned_metadata(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $survey = Survey::create(['school_id' => $admin->school_id, 'title' => 'Tukša', 'sections' => [], 'questions' => []]);
        try {
            SurveyWorkflow::publish($survey);
            $this->fail('Empty survey published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('questions', $exception->errors());
        }
        $survey = $this->survey($admin->school);
        Livewire::test(EditSurvey::class, ['record' => $survey->id])->callAction('publish')->assertHasNoActionErrors();
        $survey->refresh();
        $this->assertSame('open', $survey->status);
        $this->assertNotNull($survey->published_at);
        $publishedAt = $survey->published_at;
        $slug = $survey->slug;
        Livewire::test(EditSurvey::class, ['record' => $survey->id])
            ->set('data.builder.questions', [])
            ->set('data.builder.sections', [])
            ->call('save')->assertHasErrors(['data.builder.questions', 'data.builder.sections']);
        $this->assertSame($this->definition()['questions'], $survey->fresh()->questions);
        Livewire::test(EditSurvey::class, ['record' => $survey->id])
            ->set('data.builder.title', 'Edited published title')
            ->set('data.builder.description', 'Edited published introduction')
            ->set('data.school_id', $this->school()->id)
            ->set('data.status', 'draft')
            ->set('data.slug', 'injected')
            ->set('data.published_at', null)
            ->call('save')->assertHasNoFormErrors();
        $survey->refresh();
        $this->assertSame('Edited published title', $survey->title);
        $this->assertSame('Edited published introduction', $survey->description);
        $this->assertSame($this->definition()['questions'], $survey->questions);
        $this->assertSame($admin->school_id, $survey->school_id);
        $this->assertSame('open', $survey->status);
        $this->assertSame($slug, $survey->slug);
        $this->assertTrue($publishedAt->equalTo($survey->published_at));
    }

    public function test_builder_errors_point_to_numeric_editor_fields(): void
    {
        $admin = $this->admin();
        $survey = $this->survey($admin->school);
        $this->actingAs($admin);
        $data = $this->definition();
        $data['questions'][0]['condition'] = ['question' => 'q14', 'values' => ['invalid']];
        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id])->fillForm(SurveyEditorData::fromDefinition($data));
        $page->call('save')->assertHasErrors(['data.builder.questions.0.condition.question'])
            ->assertDispatched('survey-editor-errors');
        $this->assertNull($survey->fresh()->questions[0]['condition']);
    }

    public function test_publish_action_saves_pending_draft_edits_before_publication(): void
    {
        $admin = $this->admin();
        $survey = $this->survey($admin->school);
        $this->actingAs($admin);
        Livewire::test(EditSurvey::class, ['record' => $survey->id])
            ->set('data.builder.title', 'Pārbaudītā aptaujas versija')
            ->callAction('publish')->assertHasNoActionErrors();
        $this->assertSame('Pārbaudītā aptaujas versija', $survey->fresh()->title);
        $this->assertSame('open', $survey->fresh()->status);
    }

    public function test_close_and_copy_preserve_original_and_do_not_copy_responses_or_family_links(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $survey = SurveyWorkflow::publish($this->survey($admin->school));
        $survey->responses()->create(['answers' => ['q1' => ['value' => 'yes_definitely', 'other' => null]]]);
        $survey->invitations()->create(['school_id' => $admin->school_id, 'label' => 'Ģimene 01']);
        SurveyWorkflow::close($survey);
        $copy = SurveyWorkflow::duplicate($survey);
        $this->assertSame('closed', $survey->fresh()->status);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNotSame($survey->slug, $copy->slug);
        $this->assertSame($survey->questions, $copy->questions);
        $this->assertSame(0, $copy->responses()->count());
        $this->assertSame(0, $copy->invitations()->count());
        $this->assertSame(1, $survey->responses()->count());
    }

    public function test_foreign_survey_mutation_is_denied_even_when_called_directly(): void
    {
        $foreign = $this->survey($this->school());
        $this->actingAs($this->admin());
        $this->expectException(HttpException::class);
        SurveyWorkflow::update($foreign, ['title' => 'Changed']);
    }

    public function test_invitation_creation_assigns_school_and_survey_on_the_server(): void
    {
        $admin = $this->admin();
        $survey = $this->survey($admin->school);
        $foreign = $this->survey($this->school());
        $this->actingAs($admin);
        Livewire::test(InvitationsRelationManager::class, ['ownerRecord' => $survey, 'pageClass' => EditSurvey::class])
            ->callTableAction('create', data: ['label' => 'Ģimene 01', 'school_id' => $foreign->school_id, 'survey_id' => $foreign->id])
            ->assertHasNoTableActionErrors();
        $invitation = $survey->invitations()->firstOrFail();
        $this->assertSame($admin->school_id, $invitation->school_id);
        $this->assertNotEmpty($invitation->token);
        $this->assertSame(hash('sha256', $invitation->token), $invitation->token_hash);
        $this->assertSame(0, $foreign->invitations()->count());
        $this->assertFalse(InvitationsRelationManager::canViewForRecord($foreign, EditSurvey::class));
    }

    public function test_cli_creates_no_default_user_and_refuses_to_replace_existing_email(): void
    {
        $school = $this->school();
        $this->artisan('mikausis:admin', ['--email' => 'admin@example.test', '--name' => 'Administrators', '--generate-password' => true])->assertSuccessful();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $hash = $user->password;
        $this->assertTrue($user->is_admin);
        $this->assertSame($school->id, $user->school_id);
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->artisan('mikausis:admin', ['--email' => $user->email, '--name' => 'Replacement', '--generate-password' => true])->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, User::count());
    }
}
