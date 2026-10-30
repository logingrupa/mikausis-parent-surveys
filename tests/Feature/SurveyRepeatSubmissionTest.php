<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Support\SurveySubmissionResumeToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class SurveyRepeatSubmissionTest extends SurveyTestCase
{
    private function formUrl(?FamilyInvitation $invitation = null): string
    {
        return $invitation === null
            ? route('surveys.show', ['slug' => $this->survey->slug])
            : route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
    }

    private function restartUrl(?FamilyInvitation $invitation = null): string
    {
        return $invitation === null
            ? route('surveys.restart', ['slug' => $this->survey->slug])
            : route('surveys.family.restart', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
    }

    private function renew(string $proof, ?FamilyInvitation $invitation = null): TestResponse
    {
        $url = $invitation === null
            ? route('surveys.session', ['slug' => $this->survey->slug])
            : route('surveys.family.session', ['slug' => $this->survey->slug, 'token' => $invitation->token]);

        return $this->getJson($url, ['X-Requested-With' => 'XMLHttpRequest', 'X-Survey-Resume' => $proof]);
    }

    public static function scopes(): array
    {
        return ['public' => [false], 'family' => [true]];
    }

    public function test_public_restart_allows_two_blank_separate_submissions_and_preserves_original_answers_and_ciphertext(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->survey->update(['allow_identified_responses' => true, 'respondent_fields' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
        ]]);
        $first = $this->get($this->formUrl())->assertOk();
        $firstId = $first->viewData('submissionId');
        $this->postJson($this->formUrl(), $this->payload(['q4' => 'First private answer'], [
            'submission_id' => $firstId, 'anonymous' => false,
            'respondent' => ['name' => 'Parent One', 'email' => 'one@example.test'],
        ]))->assertOk()->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])]);
        $stored = DB::table('survey_responses')->where('id', $firstId)->first();
        $this->assertNotEmpty($stored->respondent_details);
        $this->assertStringNotContainsString('Parent One', $stored->respondent_details);

        $restart = $this->postJson($this->restartUrl())->assertOk()->assertExactJson(['redirect' => $this->formUrl()])
            ->assertSessionMissing('survey_submitted.'.$this->survey->id);
        $this->assertTrue($restart->headers->hasCacheControlDirective('private'));
        $this->assertTrue($restart->headers->hasCacheControlDirective('no-store'));
        $restartId = session('survey_submission_ids.'.$this->survey->id);
        $this->assertTrue(Str::isUuid($restartId));
        $this->assertNotSame($firstId, $restartId);

        $second = $this->get($this->formUrl())->assertOk()->assertViewHas('submitted', false)->assertViewHas('submissionId', $restartId)
            ->assertViewMissing('oldValues')->assertDontSee('First private answer')
            ->assertDontSee('Parent One')->assertDontSee('one@example.test');
        $secondId = $second->viewData('submissionId');
        $this->assertTrue(Str::isUuid($secondId));
        $this->assertNotSame($firstId, $secondId);
        $this->get($this->formUrl())->assertOk()->assertViewHas('submissionId', $secondId);
        $this->postJson($this->formUrl(), $this->payload(['q4' => 'Second private answer'], [
            'submission_id' => $secondId, 'anonymous' => false,
            'respondent' => ['name' => 'Parent Two', 'email' => 'two@example.test'],
        ]))->assertOk();

        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 0);
        $this->assertEquals($stored, DB::table('survey_responses')->where('id', $firstId)->first());
        $this->assertSame(['q4' => ['value' => 'Second private answer', 'other' => null]], $this->survey->responses()->findOrFail($secondId)->answers);
        $this->assertStringNotContainsString('private answer', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Parent One', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_old_submitted_proof_after_restart_cannot_reuse_the_saved_uuid_for_the_next_public_document(): void
    {
        $first = $this->get($this->formUrl())->assertOk();
        $firstId = $first->viewData('submissionId');
        $this->postJson($this->formUrl(), $this->payload(['q4' => 'First parent answer'], ['submission_id' => $firstId]))->assertOk();
        $this->postJson($this->restartUrl())->assertOk()->assertSessionMissing('survey_submitted.'.$this->survey->id);
        $this->renew($first->viewData('submissionResumeToken'))->assertOk()
            ->assertJsonPath('submission_id', $firstId)->assertJsonPath('submitted', true)
            ->assertSessionMissing('survey_submitted.'.$this->survey->id);

        $second = $this->get($this->formUrl())->assertOk()->assertViewHas('submitted', false)->assertDontSee('First parent answer');
        $secondId = $second->viewData('submissionId');
        $this->assertNotSame($firstId, $secondId);
        $this->renew($second->viewData('submissionResumeToken'))->assertOk()->assertJsonPath('submitted', false);
        $this->postJson($this->formUrl(), $this->payload(['q4' => 'Second parent answer'], ['submission_id' => $secondId]))->assertOk();
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertSame(['q4' => ['value' => 'First parent answer', 'other' => null]], $this->survey->responses()->findOrFail($firstId)->answers);
        $this->assertSame(['q4' => ['value' => 'Second parent answer', 'other' => null]], $this->survey->responses()->findOrFail($secondId)->answers);
    }

    public function test_family_restart_preserves_roles_and_link_and_stale_parent_proof_cannot_discard_the_next_answer(): void
    {
        $invitation = $this->invitation();
        $url = $this->formUrl($invitation);
        $first = $this->get($url)->assertOk();
        $firstId = $first->viewData('submissionId');
        $this->postJson($url, $this->payload(['q4' => 'Mother private answer'], ['submission_id' => $firstId, 'role' => 'mother']))
            ->assertOk()->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])])
            ->assertSessionHas('survey_repeat_invitations.'.$this->survey->id, $invitation->id);
        $stored = DB::table('survey_responses')->where('id', $firstId)->first();
        $participation = $invitation->participations()->sole()->getAttributes();
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('invitation', fn ($value) => $value->is($invitation))
            ->assertViewHas('draftScope', $first->viewData('draftScope'))->assertViewHas('canRepeat', true)
            ->assertDontSee($invitation->label)->assertDontSee('Mother private answer');

        $this->post($this->restartUrl($invitation))->assertRedirect($url)->assertSessionMissing('survey_submitted.'.$this->survey->id);
        $second = $this->get($url)->assertOk()->assertViewHas('submitted', false)->assertViewMissing('oldValues')
            ->assertDontSee('Mother private answer')->assertDontSee($invitation->label);
        $secondId = $second->viewData('submissionId');
        $this->assertNotSame($firstId, $secondId);
        $this->renew($first->viewData('submissionResumeToken'), $invitation)->assertOk()->assertJsonPath('submitted', true)
            ->assertSessionMissing('survey_submitted.'.$this->survey->id);
        $this->postJson($url, $this->payload(['q4' => 'Father private answer'], ['submission_id' => $firstId, 'role' => 'father']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson($url, $this->payload(['q4' => 'Father private answer'], ['submission_id' => $secondId, 'role' => 'father']))
            ->assertUnprocessable()->assertJsonValidationErrors('submission_id');
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertSame($participation, $invitation->participations()->sole()->getAttributes());

        $this->renew($second->viewData('submissionResumeToken'), $invitation)->assertOk()->assertJsonPath('submitted', false);
        $this->postJson($url, $this->payload(extra: ['submission_id' => $secondId, 'role' => 'mother']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson($url, $this->payload(['q4' => 'Father private answer'], ['submission_id' => $secondId, 'role' => 'father']))->assertOk();
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);
        $this->assertSame(['father', 'mother'], $invitation->participations()->orderBy('role')->pluck('role')->all());
        $this->assertEquals($stored, DB::table('survey_responses')->where('id', $firstId)->first());
        $this->assertSame(['q4' => ['value' => 'Father private answer', 'other' => null]], $this->survey->responses()->findOrFail($secondId)->answers);
    }

    public function test_restart_changes_only_this_surveys_flag_uuid_and_scope_and_retry_creates_no_database_rows(): void
    {
        $form = $this->get($this->formUrl())->assertOk();
        $other = $this->otherSurvey();
        $otherInvitation = $this->invitation($other);
        $otherId = (string) Str::uuid();
        $otherScope = SurveySubmissionResumeToken::draftScope($other, $otherInvitation);
        $this->withSession([
            'survey_submitted' => [$this->survey->id => true, $other->id => true],
            'survey_submission_ids' => [$this->survey->id => $form->viewData('submissionId'), $other->id => $otherId],
            'survey_submission_scopes' => [$this->survey->id => $form->viewData('draftScope'), $other->id => $otherScope],
            'survey_repeat_invitations' => [$other->id => $otherInvitation->id], 'unrelated' => 'Keep this value',
        ]);
        $csrf = session()->token();
        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($this->restartUrl())->assertOk()->assertExactJson(['redirect' => $this->formUrl()])
                ->assertSessionMissing('survey_submitted.'.$this->survey->id)
                ->assertSessionHas('survey_submission_scopes.'.$this->survey->id, $form->viewData('draftScope'))
                ->assertSessionHas('survey_submitted.'.$other->id, true)
                ->assertSessionHas('survey_submission_ids.'.$other->id, $otherId)
                ->assertSessionHas('survey_submission_scopes.'.$other->id, $otherScope)
                ->assertSessionHas('survey_repeat_invitations.'.$other->id, $otherInvitation->id)
                ->assertSessionHas('unrelated', 'Keep this value');
        }
        $this->assertTrue($csrf === session()->token());
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    #[DataProvider('scopes')]
    public function test_restart_works_without_a_previous_session_or_submitted_flag(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        session()->invalidate();
        $this->postJson($this->restartUrl($invitation))->assertOk()->assertExactJson(['redirect' => $this->formUrl($invitation)])
            ->assertSessionMissing('survey_submitted.'.$this->survey->id)
            ->assertSessionHas('survey_submission_scopes.'.$this->survey->id, SurveySubmissionResumeToken::draftScope($this->survey, $invitation));
        $this->assertTrue(Str::isUuid(session('survey_submission_ids.'.$this->survey->id)));
        $this->assertDatabaseCount('survey_responses', 0);
    }

    #[DataProvider('scopes')]
    public function test_submitted_proof_restores_repeat_context_without_relocking_the_form_or_reading_answers(bool $family): void
    {
        $invitation = $family ? $this->invitation() : null;
        $form = $this->get($this->formUrl($invitation))->assertOk();
        $this->postJson($this->formUrl($invitation), $this->payload(['q4' => 'Private previous answer'], ['submission_id' => $form->viewData('submissionId'), 'role' => 'mother']))->assertOk();
        session()->invalidate();
        if (! $family) {
            $stale = $this->invitation();
            $this->withSession(['survey_repeat_invitations' => [$this->survey->id => $stale->id]]);
        }
        $this->renew($form->viewData('submissionResumeToken'), $invitation)->assertOk()->assertJsonPath('submitted', true)
            ->assertJsonPath('redirect', route('surveys.thanks', ['slug' => $this->survey->slug]))
            ->assertSessionMissing('survey_submitted.'.$this->survey->id)->assertDontSee('Private previous answer');
        $this->assertSame($invitation?->id, session('survey_repeat_invitations.'.$this->survey->id));
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('invitation', fn ($value) => $family ? $value->is($invitation) : $value === null)
            ->assertViewHas('draftScope', $form->viewData('draftScope'))->assertViewHas('canRepeat', true)
            ->assertDontSee('Private previous answer');
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_successful_public_submission_forgets_the_previous_family_repeat_context(): void
    {
        $invitation = $this->invitation();
        $familyId = $this->get($this->formUrl($invitation))->assertOk()->viewData('submissionId');
        $this->postJson($this->formUrl($invitation), $this->payload(extra: ['submission_id' => $familyId, 'role' => 'mother']))
            ->assertOk()->assertSessionHas('survey_repeat_invitations.'.$this->survey->id, $invitation->id);
        $this->post($this->restartUrl())->assertRedirect($this->formUrl());
        $public = $this->get($this->formUrl())->assertOk();
        $this->postJson($this->formUrl(), $this->payload(extra: ['submission_id' => $public->viewData('submissionId')]))
            ->assertOk()->assertSessionMissing('survey_repeat_invitations.'.$this->survey->id);
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('invitation', null)->assertViewHas('draftScope', $public->viewData('draftScope'))->assertViewHas('canRepeat', true);
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 1);
    }

    public function test_full_family_has_no_repeat_offer_and_restart_does_not_reset_roles_or_session(): void
    {
        $invitation = $this->invitation();
        foreach (['mother', 'father', 'guardian'] as $role) {
            $invitation->participations()->create(['role' => $role]);
        }
        $id = (string) Str::uuid();
        $this->withSession(['survey_repeat_invitations' => [$this->survey->id => $invitation->id],
            'survey_submitted' => [$this->survey->id => true], 'survey_submission_ids' => [$this->survey->id => $id]]);
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('invitation', fn ($value) => $value->is($invitation))->assertViewHas('canRepeat', false)
            ->assertDontSee('data-survey-restart', false)->assertDontSee($invitation->label);
        $this->postJson($this->restartUrl($invitation))->assertUnprocessable()
            ->assertJsonPath('message', 'Šī aptauja šobrīd nepieņem atbildes.')
            ->assertSessionHas('survey_submitted.'.$this->survey->id, true)
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id);
        $this->assertSame(['father', 'guardian', 'mother'], $invitation->participations()->orderBy('role')->pluck('role')->all());
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public static function unavailable(): array
    {
        return ['closed public' => [false, false], 'expired public' => [false, true],
            'closed family' => [true, false], 'expired family' => [true, true]];
    }

    #[DataProvider('unavailable')]
    public function test_closed_and_expired_surveys_have_no_repeat_offer_and_reject_restart(bool $family, bool $expired): void
    {
        $invitation = $family ? $this->invitation() : null;
        $form = $this->get($this->formUrl($invitation))->assertOk();
        if ($invitation !== null) {
            $this->withSession(['survey_repeat_invitations' => [$this->survey->id => $invitation->id]]);
        }
        $this->survey->update($expired ? ['closes_at' => now()->subMinute()] : ['status' => 'closed']);
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()->assertViewHas('canRepeat', false)
            ->assertDontSee('data-survey-restart', false);
        $this->postJson($this->restartUrl($invitation))->assertUnprocessable()->assertJsonPath('message', 'Šī aptauja šobrīd nepieņem atbildes.')
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $form->viewData('submissionId'));
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_drafts_invalid_invitations_and_wrong_school_links_are_not_restartable(): void
    {
        $draft = $this->survey->duplicate();
        $this->postJson(route('surveys.restart', ['slug' => $draft->slug]))->assertNotFound();
        $draftInvitation = $this->invitation($draft);
        $this->postJson(route('surveys.family.restart', ['slug' => $draft->slug, 'token' => $draftInvitation->token]))->assertNotFound();
        $this->postJson(route('surveys.family.restart', ['slug' => $this->survey->slug, 'token' => 'invalid']))->assertNotFound();
        $other = $this->otherSurvey();
        $foreign = $this->invitation($other);
        $this->postJson($this->restartUrl($foreign))->assertNotFound();
        $own = $this->invitation();
        DB::table('family_invitations')->where('id', $own->id)->update(['school_id' => $other->school_id]);
        $this->postJson($this->restartUrl($own))->assertNotFound();
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_thanks_resolves_repeat_invitation_against_current_survey_and_school_without_exposing_foreign_context(): void
    {
        $other = $this->otherSurvey();
        $foreign = $this->invitation($other);
        $this->withSession(['survey_repeat_invitations' => [$this->survey->id => $foreign->id]])
            ->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('invitation', null)->assertViewHas('canRepeat', true)
            ->assertViewHas('draftScope', SurveySubmissionResumeToken::draftScope($this->survey, null))
            ->assertDontSee($foreign->token)->assertDontSee($foreign->label);
        $own = $this->invitation();
        DB::table('family_invitations')->where('id', $own->id)->update(['school_id' => $other->school_id]);
        $this->withSession(['survey_repeat_invitations' => [$this->survey->id => $own->id]])
            ->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()->assertViewHas('invitation', null)
            ->assertDontSee($own->token)->assertDontSee($own->label);
    }

    public function test_gets_and_contact_only_session_renewal_do_not_restart_or_create_completion_context(): void
    {
        $form = $this->get($this->formUrl())->assertOk();
        $id = $form->viewData('submissionId');
        $this->postJson($this->formUrl(), $this->payload(extra: ['submission_id' => $id]))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($this->restartUrl())->assertStatus(405);
        $this->getJson(route('surveys.session', ['slug' => $this->survey->slug]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonMissingPath('submission_id')->assertJsonMissingPath('submitted')
            ->assertSessionHas('survey_submitted.'.$this->survey->id, true)
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $id)
            ->assertSessionMissing('survey_repeat_invitations.'.$this->survey->id);
        $this->get(route('surveys.thanks', ['slug' => $this->survey->slug]))->assertOk()->assertViewHas('invitation', null)->assertViewHas('canRepeat', true);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount('survey_responses', 1);
    }

    #[DataProvider('scopes')]
    public function test_restart_requires_standard_csrf_and_a_valid_token_preserves_the_normal_redirect(bool $family): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $invitation = $family ? $this->invitation() : null;
        $form = $this->get($this->formUrl($invitation))->assertOk();
        $this->postJson($this->restartUrl($invitation))->assertStatus(419)
            ->assertSessionHas('survey_submission_ids.'.$this->survey->id, $form->viewData('submissionId'));
        $this->post($this->restartUrl($invitation), ['_token' => session()->token()])->assertRedirect($this->formUrl($invitation));
        $this->assertNotSame($form->viewData('submissionId'), session('survey_submission_ids.'.$this->survey->id));
        $this->assertDatabaseCount('survey_responses', 0);
    }
}
