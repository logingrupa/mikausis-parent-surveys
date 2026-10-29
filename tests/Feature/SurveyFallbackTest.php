<?php

namespace Tests\Feature;

use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;

class SurveyFallbackTest extends SurveyTestCase
{
    public static function invalidFormAnswers(): array
    {
        return [
            'exclusive choices' => [['q2' => ['a', 'no']], [], 'mother', 'answers.q2'],
            'missing other text' => [['q1' => 'other'], [], 'mother', 'other_answers.q1'],
            'malformed other text' => [['q1' => 'other'], ['q1' => ['nested']], 'mother', 'other_answers.q1'],
            'malformed multiple choice' => [['q2' => 'a'], [], 'mother', 'answers.q2'],
            'invalid family role' => [['q1' => 'yes'], [], 'parent', 'role'],
            'malformed family role' => [['q1' => 'yes'], [], ['mother'], 'role'],
        ];
    }

    #[DataProvider('invalidFormAnswers')]
    public function test_invalid_form_renders_values_and_errors_without_flashing_answers(array $answers, array $others, mixed $role, string $field): void
    {
        $invitation = $this->invitation();
        $url = route('surveys.family.submit', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $payload = $this->payload(array_replace(['q4' => 'Private answer <script>alert("x")</script>'], $answers), [
            'submission_id' => $id,
            'other_answers' => array_replace(['q4' => 'Private other answer'], $others),
            'role' => $role,
            'token' => $invitation->token,
            'unexpected' => 'Discard this field',
        ]);

        $response = $this->post($url, $payload)->assertUnprocessable()->assertViewIs('surveys.show')
            ->assertViewHas('survey', fn ($survey) => $survey->is($this->survey))
            ->assertViewHas('invitation', fn ($family) => $family->is($invitation))
            ->assertViewHas('submissionId', $id)->assertViewHas('submitted', false)
            ->assertViewHas('oldValues', array_intersect_key($payload, array_flip(['answers', 'other_answers', 'role'])))
            ->assertViewHas('errors', fn ($errors) => $errors instanceof ViewErrorBag
                && $errors->getBag('default') instanceof MessageBag && $errors->has($field))
            ->assertHeaderMissing('Location')
            ->assertSessionMissing(['_old_input', '_old_input.answers', '_old_input.other_answers', 'answers', 'other_answers', 'role', 'errors', 'survey_submitted.'.$this->survey->id]);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private answer', $session);
        $this->assertStringNotContainsString('Private other answer', $session);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
        $this->get($url)->assertOk()->assertDontSee('Private answer')->assertDontSee('Private other answer');
    }

    public static function invalidSubmissionIds(): array
    {
        $validId = '11111111-1111-4111-8111-111111111111';

        return [
            'missing session' => [null, $validId],
            'non-string session' => [[$validId], $validId],
            'matching malformed ids' => ['not-a-uuid', 'not-a-uuid'],
            'mismatched posted id' => [$validId, '22222222-2222-4222-8222-222222222222'],
            'missing posted id' => [$validId, null],
            'malformed posted id' => [$validId, [$validId]],
        ];
    }

    #[DataProvider('invalidSubmissionIds')]
    public function test_invalid_submission_id_renders_a_valid_session_id_and_allows_retry(mixed $expected, mixed $provided): void
    {
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        if ($expected !== null) {
            $this->withSession(['survey_submission_ids' => [$this->survey->id => $expected]]);
        }
        $payload = $this->payload(['q4' => 'Keep my private answer'], ['submission_id' => $provided]);
        $response = $this->post($url, $payload)->assertUnprocessable()->assertViewIs('surveys.show')
            ->assertViewHas('invitation', null)->assertViewHas('submitted', false)
            ->assertViewHas('oldValues', ['answers' => $payload['answers']])
            ->assertViewHas('errors', fn ($errors) => $errors instanceof ViewErrorBag && $errors->has('submission_id'))
            ->assertSessionMissing(['_old_input', 'errors', 'survey_submitted.'.$this->survey->id]);

        $id = $response->viewData('submissionId');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, session('survey_submission_ids.'.$this->survey->id));
        if (is_string($expected) && Str::isUuid($expected)) {
            $this->assertSame($expected, $id);
        }
        $this->assertStringNotContainsString('Keep my private answer', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);

        $payload['submission_id'] = $id;
        $this->post($url, $payload)->assertRedirect(route('surveys.thanks', ['slug' => $this->survey->slug]))
            ->assertSessionHas('survey_submitted.'.$this->survey->id, true)->assertSessionMissing('_old_input');
        $this->assertDatabaseHas('survey_responses', ['id' => $id]);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_successful_form_submission_keeps_the_thanks_redirect_and_idempotent_retry(): void
    {
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $payload = $this->payload(['q1' => 'other', 'q4' => 'Private details'], [
            'submission_id' => $id, 'other_answers' => ['q1' => 'Private other answer'],
        ]);

        $this->post($url, $payload)->assertRedirect(route('surveys.thanks', ['slug' => $this->survey->slug]))
            ->assertSessionHas('survey_submitted.'.$this->survey->id, true)->assertSessionMissing('_old_input');
        $this->post($url, $payload)->assertRedirect(route('surveys.thanks', ['slug' => $this->survey->slug]));

        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 0);
        $this->assertSame([
            'q1' => ['value' => 'other', 'other' => 'Private other answer'],
            'q4' => ['value' => 'Private details', 'other' => null],
        ], $this->survey->responses()->sole()->answers);
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private details', $session);
        $this->assertStringNotContainsString('Private other answer', $session);
    }

    public function test_json_validation_and_success_responses_keep_their_existing_shape(): void
    {
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $this->postJson($url, $this->payload(['q2' => ['a', 'no']], ['submission_id' => $id]))
            ->assertUnprocessable()->assertJsonValidationErrors('answers.q2')
            ->assertJsonMissingPath('oldValues')->assertSessionMissing(['_old_input', 'errors']);
        $this->assertSame($id, session('survey_submission_ids.'.$this->survey->id));
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);

        $this->postJson($url, $this->payload(extra: ['submission_id' => $id]))->assertOk()
            ->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])]);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_json_post_without_a_session_still_returns_validation_without_creating_an_id(): void
    {
        $this->postJson(route('surveys.submit', ['slug' => $this->survey->slug]), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('submission_id')
            ->assertSessionMissing(['survey_submission_ids.'.$this->survey->id, '_old_input']);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_stale_family_form_renders_the_role_error_without_creating_another_response(): void
    {
        $invitation = $this->invitation();
        $url = route('surveys.family.submit', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $this->post($url, $this->payload(extra: ['submission_id' => $id, 'role' => 'mother']))->assertRedirect();
        $payload = $this->payload(['q4' => 'Father private answer'], ['submission_id' => $id, 'role' => 'father']);

        $this->post($url, $payload)->assertUnprocessable()->assertViewIs('surveys.show')
            ->assertViewHas('submitted', true)->assertViewHas('submissionId', $id)
            ->assertViewHas('oldValues', ['answers' => $payload['answers'], 'role' => 'father'])
            ->assertViewHas('errors', fn ($errors) => $errors instanceof ViewErrorBag && $errors->has('role'))
            ->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
        $this->assertDatabaseMissing('family_participations', ['invitation_id' => $invitation->id, 'role' => 'father']);
        $newId = $this->get($url)->assertOk()->viewData('submissionId');
        $this->assertTrue(Str::isUuid($newId));
        $this->assertNotSame($id, $newId);
    }

    public function test_form_posts_to_invalid_tokens_and_drafts_still_return_not_found(): void
    {
        $this->post(route('surveys.family.submit', ['slug' => $this->survey->slug, 'token' => 'invalid']), $this->payload())
            ->assertNotFound()->assertSessionMissing('_old_input');
        $draft = $this->survey->duplicate();
        $this->post(route('surveys.submit', ['slug' => $draft->slug]), $this->payload())
            ->assertNotFound()->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }
}
