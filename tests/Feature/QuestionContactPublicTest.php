<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Support\Str;

class QuestionContactPublicTest extends SurveyTestCase
{
    private function contactSurvey(): Survey
    {
        $survey = $this->survey->duplicate();
        $questions = $survey->questions;
        $questions[0]['contact'] = [
            'prompt' => 'Atstāt kontaktinformāciju', 'purpose' => 'Saziņai par pavasara talku.', 'trigger_values' => ['yes'],
            'fields' => [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
            ],
        ];
        $survey->update(['questions' => $questions, 'status' => 'open']);

        return $survey;
    }

    private function url(Survey $survey, string $question = 'q1', string $route = 'show'): string
    {
        return route('question-contacts.'.$route, ['slug' => $survey->slug, 'question' => $question]);
    }

    private function contactPayload(array $extra = []): array
    {
        return array_replace([
            'contact_id' => (string) Str::uuid(), 'consent' => '1', 'answer' => 'yes',
            'fields' => ['name' => 'Sintētiskais Vecāks', 'email' => 'test@example.com'],
        ], $extra);
    }

    public function test_guest_get_uses_fresh_independent_ids_and_does_not_read_identity_or_write_survey_session_state(): void
    {
        $survey = $this->contactSurvey();
        $mainId = (string) Str::uuid();
        $this->withSession(['survey_submission_ids' => [$survey->id => $mainId], 'family_role' => 'mother']);
        $first = $this->get($this->url($survey))->assertOk()->assertViewIs('surveys.question-contact')
            ->assertViewHas('question', fn ($question) => $question['key'] === 'q1')
            ->assertViewHas('oldValues', [])
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $second = $this->get($this->url($survey))->assertOk();
        $id = $first->viewData('contactId');
        $this->assertTrue(Str::isUuid($id));
        $this->assertNotSame($mainId, $id);
        $this->assertNotSame($id, $second->viewData('contactId'));
        $this->assertSame($mainId, session('survey_submission_ids.'.$survey->id));
        $this->assertStringNotContainsString($id, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_guest_json_post_needs_no_main_submission_or_family_session_and_returns_no_contact_identifier(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload([
            'submission_id' => (string) Str::uuid(), 'answers' => ['q2' => ['a', 'b']],
            'respondent' => ['name' => 'Ignored'], 'family_id' => 123, 'ip' => '127.0.0.1',
        ]);
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk()->assertExactJson(['saved' => true]);
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk()->assertExactJson(['saved' => true]);
        $this->assertDatabaseCount('question_contacts', 1);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
        $this->assertStringNotContainsString('Sintētiskais Vecāks', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($payload['contact_id'], json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_normal_success_redirects_to_public_thanks_without_flashing_private_values(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload();
        $this->post($this->url($survey, route: 'store'), $payload)
            ->assertRedirect($this->url($survey, route: 'thanks'))->assertSessionMissing('_old_input');
        $this->get($this->url($survey, route: 'thanks'))->assertOk()->assertViewIs('surveys.question-contact-thanks')
            ->assertDontSee('Sintētiskais Vecāks')->assertDontSee('test@example.com');
        $this->assertStringNotContainsString('test@example.com', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_lost_success_response_then_corrected_email_same_uuid_returns_explicit_422_without_overwrite(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload();
        // The client may never receive this successful response, but the row is saved.
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk();
        $this->postJson($this->url($survey, route: 'store'), [
            ...$payload, 'fields' => [...$payload['fields'], 'email' => 'corrected@example.com'],
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_id')->assertDontSee('corrected@example.com');
        $this->assertSame('test@example.com', $survey->questionContacts()->sole()->details['fields'][1]['value']);
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_exact_saved_retry_after_closure_succeeds_without_accepting_new_or_changed_contact(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload();
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk();
        $survey->update(['status' => 'closed']);
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk()->assertExactJson(['saved' => true]);
        $this->postJson($this->url($survey, route: 'store'), [
            ...$payload, 'fields' => [...$payload['fields'], 'email' => 'corrected@example.com'],
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson($this->url($survey, route: 'store'), [
            ...$payload, 'contact_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_invalid_normal_post_renders_only_current_request_values_in_default_error_bag_without_session_flash(): void
    {
        $survey = $this->contactSurvey();
        $name = 'Sintētiskais Vecāks <script>private</script>';
        $payload = $this->contactPayload(['fields' => ['name' => $name, 'email' => 'invalid-email']]);
        $response = $this->post($this->url($survey, route: 'store'), $payload)->assertUnprocessable()
            ->assertViewIs('surveys.question-contact')->assertSee($name)->assertDontSee($name, false)
            ->assertViewHas('oldValues', fn ($old) => $old['fields']['name'] === $name)
            ->assertViewHas('errors', fn ($errors) => $errors->getBag('default')->has('fields.email'))
            ->assertSessionMissing(['_old_input', 'fields', 'contact_id']);
        $this->assertSame($payload['contact_id'], $response->viewData('contactId'));
        $this->assertStringNotContainsString($name, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->get($this->url($survey))->assertOk()->assertDontSee('Sintētiskais Vecāks')->assertViewHas('oldValues', []);
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_invalid_or_cross_scope_contact_id_is_replaced_for_normal_retry(): void
    {
        $survey = $this->contactSurvey();
        $response = $this->post($this->url($survey, route: 'store'), $this->contactPayload(['contact_id' => 'bad-id']))
            ->assertUnprocessable()->assertViewHas('errors', fn ($errors) => $errors->has('contact_id'));
        $this->assertTrue(Str::isUuid($response->viewData('contactId')));
        $payload = $this->contactPayload();
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertOk();
        $other = $this->contactSurvey();
        $response = $this->post($this->url($other, route: 'store'), $payload)->assertUnprocessable();
        $this->assertNotSame($payload['contact_id'], $response->viewData('contactId'));
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_json_validation_returns_422_without_reflecting_pii_or_flashing_input(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload(['consent' => '0', 'fields' => ['name' => 'SECRET PRIVATE NAME', 'email' => 'private@example.com']]);
        $this->postJson($this->url($survey, route: 'store'), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('consent')->assertDontSee('SECRET PRIVATE NAME')->assertDontSee('private@example.com')
            ->assertSessionMissing('_old_input');
        $this->assertStringNotContainsString('SECRET PRIVATE NAME', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_only_published_survey_question_with_contact_configuration_is_public(): void
    {
        $survey = $this->contactSurvey();
        $this->get($this->url($survey, 'q3'))->assertNotFound();
        $this->get($this->url($survey, 'missing'))->assertNotFound();
        $this->get($this->url($this->survey))->assertNotFound();
        $this->get($this->url($survey->duplicate()))->assertNotFound();
        Survey::query()->whereKey($survey->id)->update(['allow_identified_responses' => true]);
        $this->get($this->url($survey))->assertNotFound();
        $this->postJson($this->url($survey, route: 'store'), $this->contactPayload())->assertNotFound();
    }

    public function test_closed_published_page_can_be_shown_but_new_submissions_are_rejected(): void
    {
        $survey = $this->contactSurvey();
        $survey->update(['status' => 'closed']);
        $this->get($this->url($survey))->assertOk()->assertViewHas('survey', fn ($survey) => ! $survey->isAcceptingResponses());
        $this->postJson($this->url($survey, route: 'store'), $this->contactPayload())->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertDatabaseCount('question_contacts', 0);
    }
}
