<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Models\Survey;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class SurveySessionRecoveryTest extends SurveyTestCase
{
    private const AJAX_HEADERS = ['X-Requested-With' => 'XMLHttpRequest'];

    public static function scopes(): array
    {
        return ['anonymous' => [false], 'family' => [true]];
    }

    private function formUrl(?FamilyInvitation $invitation = null): string
    {
        return $invitation === null
            ? route('surveys.show', ['slug' => $this->survey->slug])
            : route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
    }

    private function sessionUrl(?FamilyInvitation $invitation = null, ?Survey $survey = null): string
    {
        $survey ??= $this->survey;

        return $invitation === null
            ? route('surveys.session', ['slug' => $survey->slug])
            : route('surveys.family.session', ['slug' => $survey->slug, 'token' => $invitation->token]);
    }

    #[DataProvider('scopes')]
    public function test_contact_only_bootstrap_returns_only_csrf_and_does_not_create_main_identifiers(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $response = $this->getJson($this->sessionUrl($invitation), self::AJAX_HEADERS)->assertOk()
            ->assertJsonStructure(['csrf_token'])
            ->assertSessionMissing(['survey_submission_ids', 'survey_submitted', '_old_input'])
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->assertSame(['csrf_token'], array_keys($response->json()));
        $this->assertTrue(is_string($response->json('csrf_token')) && strlen($response->json('csrf_token')) === 40);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_contact_only_renewal_preserves_main_state_without_reading_answers_or_contact_identity(): void
    {
        $id = (string) Str::uuid();
        $this->survey->responses()->create(['id' => $id, 'answers' => ['q4' => ['value' => 'Private stored answer', 'other' => null]]]);
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $id], 'survey_submitted' => [$this->survey->id => true]]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson($this->sessionUrl().'?submission_id='.(string) Str::uuid(), self::AJAX_HEADERS)->assertOk()
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id)
            ->assertSessionHas('survey_submitted.'.$this->survey->id, true);

        $this->assertSame(['csrf_token'], array_keys($response->json()));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/survey_responses|question_answers|question_contacts|family_participations/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    #[DataProvider('scopes')]
    public function test_lost_session_rebinds_the_original_proven_uuid_before_submission(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        $form = $this->get($this->formUrl($invitation))->assertOk()->assertViewHas('submissionResumeToken');
        $id = $form->viewData('submissionId');
        $proof = $form->viewData('submissionResumeToken');
        $this->assertTrue(is_string($proof) && ! str_contains($proof, $id));
        session()->invalidate();
        $this->travel(3)->hours();

        $response = $this->getJson($this->sessionUrl($invitation), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $proof])->assertOk()
            ->assertJsonPath('submission_id', $id)
            ->assertJsonPath('submitted', false)->assertJsonMissingPath('redirect')
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id)
            ->assertSessionMissing(['survey_submitted', 'answers', 'other_answers', 'respondent', 'fields', '_old_input']);
        $this->assertSame(['csrf_token', 'submission_id', 'submitted'], array_keys($response->json()));

        $this->postJson($this->formUrl($invitation), $this->payload(['q4' => 'Private answer'], ['submission_id' => $id, 'role' => 'mother']))
            ->assertOk()->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])]);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', $family ? 1 : 0);
        $this->assertStringNotContainsString('Private answer', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_expired_csrf_is_rejected_then_renewed_token_allows_the_original_submission(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $url = $this->formUrl();
        $form = $this->get($url)->assertOk();
        $oldToken = session()->token();
        $id = $form->viewData('submissionId');
        session()->invalidate();
        $payload = $this->payload(extra: ['submission_id' => $id, '_token' => $oldToken]);

        $this->postJson($url, $payload)->assertStatus(419);
        $renewed = $this->getJson($this->sessionUrl(), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])->assertOk();
        $this->assertTrue(is_string($renewed->json('csrf_token')) && $renewed->json('csrf_token') !== $oldToken);
        $payload['_token'] = $renewed->json('csrf_token');
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('survey_responses', ['id' => $id]);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    #[DataProvider('scopes')]
    public function test_uncertain_success_can_resume_over_a_new_form_id_and_retry_after_closure_without_overwriting_answers(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        $url = $this->formUrl($invitation);
        $form = $this->get($url)->assertOk();
        $id = $form->viewData('submissionId');
        $payload = $this->payload(['q4' => 'Original private answer'], [
            'submission_id' => $id, 'role' => 'mother', 'form_definition' => $this->survey->formDefinitionToken(),
        ]);
        $this->postJson($url, $payload)->assertOk();
        session()->invalidate();
        $replacement = $this->get($url)->assertOk()->viewData('submissionId');
        $this->assertNotSame($id, $replacement);
        $this->survey->update(['title' => 'Updated title', 'status' => 'closed']);
        $definition = $this->survey->fresh()->getAttributes();

        $recovered = $this->getJson($this->sessionUrl($invitation), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])
            ->assertOk()->assertJsonPath('submission_id', $id)->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id)
            ->assertJsonPath('submitted', true)
            ->assertJsonPath('redirect', route('surveys.thanks', ['slug' => $this->survey->slug]));
        $this->assertSame(['csrf_token', 'submission_id', 'submitted', 'redirect'], array_keys($recovered->json()));
        $this->assertStringNotContainsString('Original private answer', $recovered->getContent());
        $payload['answers'] = ['q4' => 'Uncertain retry must not replace stored answers'];
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertSame(['q4' => ['value' => 'Original private answer', 'other' => null]], $this->survey->responses()->sole()->answers);
        $this->assertDatabaseCount('family_participations', $family ? 1 : 0);
        $this->assertSame($definition, $this->survey->fresh()->getAttributes());
    }

    public function test_two_valid_forms_can_rebind_their_own_ids_before_each_submission(): void
    {
        $url = $this->formUrl();
        $first = $this->get($url)->assertOk();
        session()->invalidate();
        $second = $this->get($url)->assertOk();

        foreach ([$first, $second, $first] as $form) {
            $id = $form->viewData('submissionId');
            $this->getJson($this->sessionUrl(), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])
                ->assertOk()->assertJsonPath('submission_id', $id);
            $this->postJson($url, $this->payload(extra: ['submission_id' => $id]))->assertOk();
        }
        $this->assertDatabaseCount('survey_responses', 2);
    }

    public function test_wrong_survey_or_invitation_scope_cannot_rebind_a_session(): void
    {
        $first = $this->invitation();
        $second = $this->invitation();
        $publicProof = $this->get($this->formUrl())->assertOk()->viewData('submissionResumeToken');
        $familyProof = $this->get($this->formUrl($first))->assertOk()->viewData('submissionResumeToken');
        $id = (string) Str::uuid();
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $id]]);
        $other = $this->otherSurvey();

        foreach ([
            [$this->sessionUrl($second), $familyProof],
            [$this->sessionUrl(), $familyProof],
            [$this->sessionUrl($first), $publicProof],
            [$this->sessionUrl(survey: $other), $publicProof],
        ] as [$url, $proof]) {
            $this->getJson($url, [...self::AJAX_HEADERS, 'X-Survey-Resume' => $proof])->assertForbidden()
                ->assertJsonMissingPath('csrf_token')->assertJsonMissingPath('submission_id')
                ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id)
                ->assertSessionMissing('survey_submission_ids.'.$other->id);
        }
    }

    public function test_malformed_tampered_and_unrelated_encrypted_proofs_fail_without_binding(): void
    {
        $proof = $this->get($this->formUrl())->assertOk()->viewData('submissionResumeToken');
        session()->invalidate();

        foreach (['', 'not-a-proof', (string) Str::uuid(), str_repeat('x', 4097), substr($proof, 0, -4).'AAAA',
            Crypt::encryptString('null'), Crypt::encryptString('[]'), Crypt::encryptString('{'),
            Crypt::encryptString(json_encode(['purpose' => 'other', 'submission_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR)),
        ] as $invalid) {
            $this->getJson($this->sessionUrl(), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $invalid])->assertForbidden()
                ->assertJsonMissingPath('csrf_token')->assertJsonMissingPath('submission_id')
                ->assertSessionMissing('survey_submission_ids');
        }
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public static function untrustedHeaders(): array
    {
        return [
            'ordinary navigation' => [[]],
            'cross site' => [[...self::AJAX_HEADERS, 'Sec-Fetch-Site' => 'cross-site']],
            'same site subdomain' => [[...self::AJAX_HEADERS, 'Sec-Fetch-Site' => 'same-site']],
            'no origin navigation' => [[...self::AJAX_HEADERS, 'Sec-Fetch-Site' => 'none']],
            'foreign origin' => [[...self::AJAX_HEADERS, 'Origin' => 'http://other.test']],
            'null origin' => [[...self::AJAX_HEADERS, 'Origin' => 'null']],
            'wrong scheme' => [[...self::AJAX_HEADERS, 'Origin' => 'https://mikausis.test']],
            'wrong port' => [[...self::AJAX_HEADERS, 'Origin' => 'http://mikausis.test:8080']],
        ];
    }

    #[DataProvider('untrustedHeaders')]
    public function test_untrusted_requests_are_rejected_without_credentials_cors_or_main_state(array $headers): void
    {
        $this->getJson('http://mikausis.test/a/'.$this->survey->slug.'/session', $headers)->assertForbidden()
            ->assertJsonMissingPath('csrf_token')->assertSessionMissing('survey_submission_ids')
            ->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_same_origin_metadata_is_accepted_and_valid_csrf_is_reused(): void
    {
        $url = 'http://mikausis.test/a/'.$this->survey->slug.'/session';
        $headers = [...self::AJAX_HEADERS, 'Origin' => 'http://mikausis.test', 'Sec-Fetch-Site' => 'same-origin'];
        $token = $this->getJson($url, $headers)->assertOk()->json('csrf_token');
        $response = $this->getJson($url, $headers)->assertOk();
        $this->assertTrue($token === $response->json('csrf_token'));
    }

    public function test_publication_and_family_lookup_rules_match_the_main_form(): void
    {
        $draft = $this->survey->duplicate();
        $this->getJson($this->sessionUrl(survey: $draft), self::AJAX_HEADERS)->assertNotFound();
        $this->getJson(route('surveys.family.session', ['slug' => $this->survey->slug, 'token' => 'invalid']), self::AJAX_HEADERS)->assertNotFound();
        $other = $this->otherSurvey();
        $foreign = $this->invitation($other);
        $this->getJson($this->sessionUrl($foreign), self::AJAX_HEADERS)->assertNotFound();
        $invitation = $this->invitation();
        DB::table('family_invitations')->where('id', $invitation->id)->update(['school_id' => $other->school_id]);
        $this->getJson($this->sessionUrl($invitation), self::AJAX_HEADERS)->assertNotFound();
        $this->survey->update(['status' => 'closed']);
        $this->getJson($this->sessionUrl(), self::AJAX_HEADERS)->assertOk()->assertSessionMissing('survey_submission_ids');
    }

    public function test_resuming_a_changed_or_closed_form_does_not_bypass_submission_validation(): void
    {
        $form = $this->get($this->formUrl())->assertOk();
        $payload = $this->payload(extra: ['submission_id' => $form->viewData('submissionId'), 'form_definition' => $this->survey->formDefinitionToken()]);
        $questions = $this->survey->questions;
        $questions[0]['type'] = 'multiple';
        $this->survey->update(['questions' => $questions]);
        $definition = $this->survey->fresh()->getAttributes();
        session()->invalidate();

        $this->getJson($this->sessionUrl(), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])->assertOk();
        $this->postJson($this->formUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertSame($definition, $this->survey->fresh()->getAttributes());
        $this->survey->update(['status' => 'closed']);
        $this->getJson($this->sessionUrl(), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])->assertOk();
        $this->postJson($this->formUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_draft_scope_is_stable_opaque_and_separate_for_each_survey_and_invitation(): void
    {
        $public = $this->get($this->formUrl())->assertOk();
        $scope = $public->viewData('draftScope');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scope);
        session()->invalidate();
        $this->get($this->formUrl())->assertOk()->assertViewHas('draftScope', $scope);
        $first = $this->invitation();
        $second = $this->invitation();
        $family = $this->get($this->formUrl($first))->assertOk();
        $familyScope = $family->viewData('draftScope');
        $this->assertNotSame($scope, $familyScope);
        $this->assertNotSame($familyScope, $this->get($this->formUrl($second))->assertOk()->viewData('draftScope'));
        $other = $this->otherSurvey();
        $this->assertNotSame($scope, $this->get(route('surveys.show', ['slug' => $other->slug]))->assertOk()->viewData('draftScope'));

        $fallback = $this->post($this->formUrl($first), $this->payload(['q2' => ['a', 'no']], ['submission_id' => $family->viewData('submissionId'), 'role' => 'mother']))
            ->assertUnprocessable()->assertViewHas('draftScope', $familyScope)->assertViewHas('submissionResumeToken');
        session()->invalidate();
        $this->getJson($this->sessionUrl($first), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $fallback->viewData('submissionResumeToken')])
            ->assertOk()->assertJsonPath('submission_id', $fallback->viewData('submissionId'));
    }

    public static function concurrentScopes(): array
    {
        return ['two invitations' => ['family', 'other'], 'same invitation' => ['family', 'family'],
            'public then family' => ['public', 'family'], 'family then public' => ['family', 'public']];
    }

    #[DataProvider('concurrentScopes')]
    public function test_concurrent_family_and_public_forms_never_report_another_forms_success(string $firstScope, string $secondScope): void
    {
        $invitation = $this->invitation();
        $firstInvitation = $firstScope === 'public' ? null : $invitation;
        $secondInvitation = $secondScope === 'public' ? null : ($secondScope === 'other' ? $this->invitation() : $invitation);
        $first = $this->get($this->formUrl($firstInvitation))->assertOk();
        $second = $this->get($this->formUrl($secondInvitation))->assertOk();
        $this->assertNotSame($first->viewData('submissionId'), $second->viewData('submissionId'));

        foreach ([[$first, $firstInvitation, 'mother', 'First answer'], [$second, $secondInvitation, $firstInvitation === $secondInvitation ? 'father' : 'mother', 'Second answer']] as [$form, $family, $role, $answer]) {
            $this->getJson($this->sessionUrl($family), [...self::AJAX_HEADERS, 'X-Survey-Resume' => $form->viewData('submissionResumeToken')])
                ->assertOk()->assertJsonPath('submitted', false)->assertJsonMissingPath('redirect');
            $this->postJson($this->formUrl($family), $this->payload(['q4' => $answer], ['submission_id' => $form->viewData('submissionId'), 'role' => $role]))->assertOk();
            $this->assertDatabaseHas('survey_responses', ['id' => $form->viewData('submissionId')]);
        }
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', (int) ($firstInvitation !== null) + (int) ($secondInvitation !== null));
        $this->assertSame(['First answer', 'Second answer'], $this->survey->responses()->get()->map(fn ($response) => $response->answers['q4']['value'])->sort()->values()->all());
    }

    #[DataProvider('scopes')]
    public function test_rendered_form_wires_scoped_recovery_and_loads_helpers_before_runtime(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        $form = $this->get($this->formUrl($invitation))->assertOk();
        $form->assertSee('data-session-url="'.$this->sessionUrl($invitation).'"', false)
            ->assertSee('data-draft-scope="'.$form->viewData('draftScope').'"', false)
            ->assertSee('data-session-resume="'.e($form->viewData('submissionResumeToken')).'"', false)
            ->assertSeeInOrder(['js/survey-session.js', 'js/survey-draft-state.js', 'js/contact-collector.js', 'js/survey.js'], false);
    }

    public function test_completed_public_form_provides_only_the_matching_draft_cleanup_scope(): void
    {
        $form = $this->get($this->formUrl())->assertOk();
        $this->withSession(['survey_submitted' => [$this->survey->id => true]])
            ->get($this->formUrl())->assertOk()
            ->assertSee('data-completed-draft-scope="'.$form->viewData('draftScope').'"', false)
            ->assertDontSee('id="survey-form"', false);
    }
}
