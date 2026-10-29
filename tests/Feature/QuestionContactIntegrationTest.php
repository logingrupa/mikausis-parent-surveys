<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\User;
use App\Services\QuestionContactService;
use App\Services\SurveyReportService;
use App\Services\SurveySubmissionService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

class QuestionContactIntegrationTest extends SurveyTestCase
{
    private function contactSurvey(): Survey
    {
        $questions = $this->survey->questions;
        $questions[0]['contact'] = [
            'prompt' => 'Atstāt kontaktus talkai',
            'purpose' => 'Saziņai par talku.',
            'trigger_values' => ['yes'],
            'fields' => [
                ['key' => 'name', 'label' => 'Jūsu vārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'Saziņas e-pasts', 'type' => 'email', 'required' => true],
            ],
        ];

        return Survey::create([
            'school_id' => $this->school->id, 'title' => 'Talka un anonīms viedoklis',
            'sections' => $this->survey->sections, 'questions' => $questions, 'status' => 'open',
        ]);
    }

    public function test_inline_contact_inputs_cannot_enter_the_questionnaire_form_payload(): void
    {
        $survey = $this->contactSurvey();
        $html = $this->get(route('surveys.show', $survey->slug))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $this->assertStringNotContainsString('Ja vēlaties atstāt kontaktus šai aktivitātei, tos varēsiet norādīt vienreiz aptaujas beigās.', $html);
        $this->assertSame(0, $xpath->query('//*[@data-contact-mode] | //*[@data-question]//noscript')->length);
        $this->assertSame(1, $xpath->query('//*[@data-question-contact][@hidden]')->length);
        $block = $xpath->query('//*[@data-question-contact]')->item(0);
        $this->assertSame('Atstāt kontaktus talkai', $block->getAttribute('data-contact-prompt'));
        $this->assertSame('Saziņai par talku.', $block->getAttribute('data-contact-purpose'));
        $this->assertSame('["yes"]', $block->getAttribute('data-triggers'));
        $this->assertSame(route('question-contacts.store', ['slug' => $survey->slug, 'question' => 'q1']), $block->getAttribute('data-endpoint'));
        $this->assertSame(1, $xpath->query('//*[@data-shared-contacts]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-step="1"]//*[@data-shared-contacts][@hidden]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-question]//*[@data-shared-contacts]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-contact-field]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-contact-field or @data-contact-consent][@name]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-contact-consent][@checked]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-contact-field][@disabled]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-contact-consent][@disabled]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-shared-contact-consent][@name]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-shared-contact-consent][@disabled]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-shared-contacts]//input[@type="checkbox"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-shared-contact-consent][@checked]')->length);
        $this->assertSame(1, $xpath->query('//ul[@data-shared-contact-activities][@aria-labelledby="shared-contact-activities-label"]')->length);
        $this->assertStringContainsString('Pēc noklusējuma atbildat anonīmi.', $html);
        $this->assertStringContainsString('pārējās aptaujas atbildes paliek anonīmas.', $html);
        $contactId = $xpath->query('//*[@data-question-contact]')->item(0)->getAttribute('data-contact-id');
        $submissionId = $xpath->query('//input[@name="submission_id"]')->item(0)->getAttribute('value');
        $this->assertTrue(Str::isUuid($contactId));
        $this->assertNotSame($submissionId, $contactId);
        $this->get(route('surveys.thanks', $survey->slug))->assertOk()->assertSee(route('question-contacts.show', ['slug' => $survey->slug, 'question' => 'q1']), false);
    }

    public function test_editor_preview_offers_contacts_only_in_the_final_collector(): void
    {
        $html = view('filament.forms.survey-editor', [
            'getStatePath' => fn () => 'data.builder',
            'isDisabled' => fn () => false,
            'getLivewire' => fn () => new \stdClass,
            'getRecord' => fn () => null,
            'errors' => new ViewErrorBag,
        ])->render();

        $this->assertStringNotContainsString('Ja vēlaties atstāt kontaktus šai aktivitātei, tos varēsiet norādīt vienreiz aptaujas beigās.', $html);
        $this->assertStringNotContainsString('previewContactAvailable(question)', $html);
        $this->assertStringNotContainsString('class="se-contact-preview"', $html);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="preview-shared-contacts-title"]')->length);
        $this->assertSame(0, $xpath->query('//article//section[@aria-labelledby="preview-shared-contacts-title"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="preview-shared-contact-consent"][@form="survey-editor-preview-only"]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="preview-shared-contacts-title"]//input[@type="checkbox"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="preview-shared-contact-consent"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="preview-shared-contacts-title"]//ul//li')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="preview-shared-contacts-title"]//ul//input | //section[@aria-labelledby="preview-shared-contacts-title"]//ul//label')->length);
        $this->assertStringNotContainsString('setSharedPreviewIncluded', $html);
        $this->assertStringContainsString('Pēc noklusējuma atbildat anonīmi.', $html);
        $this->assertStringContainsString('x-for="(activity, activityIndex) in previewShared.activities"', $html);
        $this->assertStringContainsString('x-for="(field, fieldIndex) in previewShared.fields"', $html);
    }

    public function test_normal_submission_keeps_separate_contact_links_on_the_thanks_page(): void
    {
        $survey = $this->contactSurvey();
        $id = $this->get(route('surveys.show', $survey->slug))->assertOk()->viewData('submissionId');
        $this->post(route('surveys.submit', $survey->slug), $this->payload(extra: ['submission_id' => $id]))
            ->assertRedirect(route('surveys.thanks', $survey->slug));
        $contactUrl = route('question-contacts.show', ['slug' => $survey->slug, 'question' => 'q1']);
        $html = $this->get(route('surveys.thanks', $survey->slug))->assertOk()
            ->assertSee('Vai vēlaties atstāt kontaktinformāciju?')->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $links = (new DOMXPath($document))->query('//section[@aria-labelledby="followup-title"]//a');
        $this->assertSame(1, $links->length);
        $this->assertSame($contactUrl, $links->item(0)->getAttribute('href'));
        $this->get($contactUrl)->assertOk()->assertViewIs('surveys.question-contact')
            ->assertSee('name="fields[email]"', false);
        $this->assertNull($survey->responses()->sole()->respondent_details);
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_main_post_keeps_activity_answer_detached_and_does_not_collect_injected_contacts(): void
    {
        $survey = $this->contactSurvey();
        $url = route('surveys.show', $survey->slug);
        $id = $this->get($url)->viewData('submissionId');
        $this->postJson($url, $this->payload(['q1' => 'yes', 'q4' => 'Privāts viedoklis'], [
            'submission_id' => $id,
            'fields' => ['name' => 'Must never reach response'],
            'contact_id' => (string) Str::uuid(),
        ]))->assertOk();
        $response = $survey->responses()->firstOrFail();
        $this->assertArrayNotHasKey('q1', $response->answers);
        $this->assertSame('Privāts viedoklis', $response->answers['q4']['value']);
        $this->assertNull($response->respondent_details);
        $this->assertSame(0, $survey->questionContacts()->count());
        $this->assertSame(1, $survey->questionAnswers()->count());
        $this->assertNotSame($id, $survey->questionAnswers()->first()->id);
    }

    public function test_reports_include_detached_answers_but_never_question_contact_names(): void
    {
        $survey = $this->contactSurvey();
        foreach (range(0, 4) as $index) {
            $answers = ['q4' => 'Anonīms viedoklis '.$index];
            if ($index < 4) {
                $answers['q1'] = $index < 3 ? 'yes' : 'no';
            }
            app(SurveySubmissionService::class)->submit($survey, $this->payload($answers));
        }
        app(QuestionContactService::class)->submit($survey, 'q1', [
            'contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes',
            'fields' => ['name' => 'VOLUNTEER PRIVATE NAME', 'email' => 'private-volunteer@example.test'],
        ]);
        $report = app(SurveyReportService::class)->build($survey, true);
        $question = $report['questions'][0];
        $this->assertSame(5, $report['response_count']);
        $this->assertSame(4, $question['answered']);
        $this->assertSame(1, $question['skipped']);
        $this->assertSame(3, $question['options'][0]['count']);
        $this->assertSame(75.0, $question['options'][0]['percent']);
        $this->assertCount(4, $question['answer_rows']);
        $this->assertSame([null], array_values(array_unique(array_column($question['answer_rows'], 'respondent'))));
        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE NAME', $serialized);
        $this->assertStringNotContainsString('private-volunteer', $serialized);
        $admin = new User;
        $admin->forceFill(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'synthetic-password', 'school_id' => $this->school->id, 'is_admin' => true])->save();
        $this->actingAs($admin);
        foreach (['reports.html', 'reports.html.download', 'reports.export'] as $route) {
            $this->get(route($route, $survey))->assertOk()->assertDontSee('PRIVATE NAME')->assertDontSee('private-volunteer');
        }
    }
}
