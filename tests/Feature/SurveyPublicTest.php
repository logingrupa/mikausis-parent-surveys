<?php

namespace Tests\Feature;

use App\Models\SurveyResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SurveyPublicTest extends SurveyTestCase
{
    public function test_configured_public_source_link_is_escaped_and_available_in_footer_and_privacy_page(): void
    {
        $url = 'https://github.com/example/survey-app?tab=readme&section=privacy';
        config(['app.source_url' => $url]);

        $this->get(route('surveys.show', $this->survey->slug))->assertOk()
            ->assertSee('href="'.e($url).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('Pirmkods');
        $this->get(route('surveys.privacy'))->assertOk()
            ->assertSee('Lietotnes pirmkods')
            ->assertSee('publiskajā pirmkoda repozitorijā')
            ->assertSee('href="'.e($url).'"', false);
    }

    public function test_source_link_is_omitted_without_a_safe_public_https_url(): void
    {
        foreach ([null, '', [], 'http://github.com/example/survey-app', 'javascript:alert(1)', '/source', 'https://user@example.com/repo', 'https://user:password@example.com/repo'] as $url) {
            config(['app.source_url' => $url]);

            $this->get(route('surveys.show', $this->survey->slug))->assertOk()->assertDontSee('Pirmkods');
            $this->get(route('surveys.privacy'))->assertOk()
                ->assertDontSee('Lietotnes pirmkods')->assertDontSee('Pirmkods');
        }
    }

    public function test_contact_privacy_explains_one_optional_consent_and_automatic_activities(): void
    {
        $this->get(route('surveys.privacy'))->assertOk()
            ->assertSee('Pēc noklusējuma atbildat anonīmi un kontaktu atstāšana nav atzīmēta.')
            ->assertSee('tās parādām informācijai, bez papildu ķeksīšiem.')
            ->assertSee('pārējās aptaujas atbildes paliek anonīmas.')
            ->assertSee('pārlūkā ievadītie kontakti tiek notīrīti.');
    }

    public function test_loopback_proxy_preserves_https_in_public_assets_and_form_actions(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://tunnel.example.test/a/'.$this->survey->slug)
            ->assertOk()
            ->assertSee('https://tunnel.example.test/css/survey.css', false)
            ->assertSee('action="https://tunnel.example.test/a/'.$this->survey->slug.'"', false);
    }

    public function test_remote_clients_cannot_override_the_request_scheme_with_proxy_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://untrusted.example.test/a/'.$this->survey->slug)
            ->assertOk()
            ->assertSee('http://untrusted.example.test/css/survey.css', false);
    }

    public function test_completed_family_link_shows_thanks_without_an_unusable_form(): void
    {
        $invitation = $this->invitation();
        foreach (['mother', 'father', 'guardian'] as $role) {
            $invitation->participations()->create(['role' => $role]);
        }

        $this->get(route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]))
            ->assertOk()->assertSee('Paldies jūsu ģimenei!')
            ->assertDontSee('id="survey-form"', false)
            ->assertDontSee($invitation->label);
    }

    public function test_show_generates_one_session_submission_id_and_does_not_expose_existing_answers(): void
    {
        $this->survey->responses()->create(['answers' => ['q4' => ['value' => 'Private existing answer', 'other' => null]]]);
        $response = $this->get(route('surveys.show', ['slug' => $this->survey->slug]));
        $response->assertOk()->assertViewIs('surveys.show')->assertViewHas('submitted', false);
        $id = $response->viewData('submissionId');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, session('survey_submission_ids.'.$this->survey->id));
        $response->assertDontSee('Private existing answer');
        $this->get(route('surveys.show', ['slug' => $this->survey->slug]))->assertViewHas('submissionId', $id);
    }

    public function test_json_submission_uses_session_uuid_and_returns_only_thanks_redirect(): void
    {
        $payload = $this->payload();
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->postJson($url, $payload)->assertOk()->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])]);
        $this->assertTrue(session('survey_submitted.'.$this->survey->id));
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_form_submission_redirects_without_putting_answers_or_response_ids_in_url(): void
    {
        $payload = $this->payload();
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->post(route('surveys.submit', ['slug' => $this->survey->slug]), $payload)
            ->assertRedirect(route('surveys.thanks', ['slug' => $this->survey->slug]));
    }

    public function test_forged_submission_uuid_and_direct_post_without_session_are_rejected(): void
    {
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        $this->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('submission_id');
        $payload = $this->payload();
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('submission_id');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_malformed_visible_answers_return_json_validation_errors(): void
    {
        $payload = $this->payload(['q2' => ['a', 'no']]);
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->postJson(route('surveys.submit', ['slug' => $this->survey->slug]), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('answers.q2');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_invalid_token_and_token_from_another_survey_return_not_found(): void
    {
        $otherInvitation = $this->invitation($this->otherSurvey());
        foreach (['invalid', $otherInvitation->token] as $token) {
            $this->get(route('surveys.family', ['slug' => $this->survey->slug, 'token' => $token]))->assertNotFound();
            $this->postJson(route('surveys.family.submit', ['slug' => $this->survey->slug, 'token' => $token]), $this->payload())->assertNotFound();
        }
    }

    public function test_family_link_never_renders_family_label_and_requires_parent_role(): void
    {
        $invitation = $this->invitation();
        $this->get(route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]))
            ->assertOk()->assertDontSee($invitation->label);
        $payload = $this->payload();
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->postJson(route('surveys.family.submit', ['slug' => $this->survey->slug, 'token' => $invitation->token]), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_parents_can_submit_the_same_family_link_in_one_browser_without_linking_roles_to_answers(): void
    {
        $invitation = $this->invitation();
        $url = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $motherId = $this->get($url)->assertOk()->viewData('submissionId');
        $motherPayload = $this->payload(['q4' => 'First private answer'], ['submission_id' => $motherId, 'role' => 'mother']);

        $this->postJson($url, $motherPayload)->assertOk()
            ->assertExactJson(['redirect' => route('surveys.thanks', ['slug' => $this->survey->slug])]);
        $this->postJson($url, $motherPayload)->assertOk();
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
        $publicForm = $this->get(route('surveys.show', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('submitted', true)
            ->assertDontSee('id="survey-form"', false)->assertDontSee('First private answer');
        $publicId = $publicForm->viewData('submissionId');
        $this->assertTrue(Str::isUuid($publicId));
        $this->assertNotSame($motherId, $publicId);

        $fatherForm = $this->get($url)->assertOk()->assertViewHas('submitted', true)
            ->assertSee('id="survey-form"', false)->assertDontSee('First private answer')->assertDontSee($invitation->label);
        $fatherId = $fatherForm->viewData('submissionId');
        $this->assertTrue(Str::isUuid($fatherId));
        $this->assertNotSame($motherId, $fatherId);
        $this->assertNotSame($publicId, $fatherId);

        $fatherPayload = $this->payload(['q4' => 'Second private answer'], ['submission_id' => $fatherId, 'role' => 'father']);
        $this->postJson($url, $fatherPayload)->assertOk();
        $this->postJson($url, $fatherPayload)->assertOk();

        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);
        $this->assertSame(['father', 'mother'], $invitation->participations()->orderBy('role')->pluck('role')->all());
        $this->assertSame(['First private answer', 'Second private answer'], $this->survey->responses()->get()
            ->map(fn ($response) => $response->answers['q4']['value'])->sort()->values()->all());
        foreach ($this->survey->responses()->get() as $response) {
            $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], array_keys($response->getAttributes()));
            $this->assertSame(['q4'], array_keys($response->answers));
        }
        foreach ($invitation->participations()->get() as $participation) {
            $this->assertSame(['id', 'invitation_id', 'role'], array_keys($participation->getAttributes()));
        }
    }

    public function test_family_links_for_the_same_survey_have_separate_ids_and_participation_records(): void
    {
        $firstInvitation = $this->invitation();
        $secondInvitation = $this->invitation();
        $firstUrl = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $firstInvitation->token]);
        $secondUrl = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $secondInvitation->token]);
        $firstForm = $this->get($firstUrl)->assertOk();
        $firstId = $firstForm->viewData('submissionId');
        $secondUnsavedId = $this->get($secondUrl)->assertOk()->viewData('submissionId');
        $this->assertNotSame($firstId, $secondUnsavedId);
        $this->getJson(route('surveys.family.session', ['slug' => $this->survey->slug, 'token' => $firstInvitation->token]), [
            'X-Requested-With' => 'XMLHttpRequest', 'X-Survey-Resume' => $firstForm->viewData('submissionResumeToken'),
        ])->assertOk()->assertJsonPath('submission_id', $firstId)->assertJsonPath('submitted', false);
        $this->postJson($firstUrl, $this->payload(['q4' => 'First family private answer'], ['submission_id' => $firstId, 'role' => 'mother']))->assertOk();

        $secondId = $this->get($secondUrl)->assertOk()->viewData('submissionId');
        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame($secondUnsavedId, $secondId);
        $this->postJson($secondUrl, $this->payload(['q4' => 'Second family private answer'], ['submission_id' => $secondId, 'role' => 'mother']))->assertOk();

        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);
        $this->assertDatabaseHas('family_participations', ['invitation_id' => $firstInvitation->id, 'role' => 'mother']);
        $this->assertDatabaseHas('family_participations', ['invitation_id' => $secondInvitation->id, 'role' => 'mother']);
        $this->assertSame(['q4' => ['value' => 'First family private answer', 'other' => null]], $this->survey->responses()->findOrFail($firstId)->answers);
        $this->assertSame(['q4' => ['value' => 'Second family private answer', 'other' => null]], $this->survey->responses()->findOrFail($secondId)->answers);
    }

    public function test_generic_page_keeps_submitted_state_and_uses_a_separate_stable_uuid_after_family_pages(): void
    {
        $url = route('surveys.show', ['slug' => $this->survey->slug]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $this->postJson($url, $this->payload(extra: ['submission_id' => $id]))->assertOk();

        $this->get($url)->assertOk()->assertViewHas('submitted', true)->assertViewHas('submissionId', $id)
            ->assertSee('Šajā pārlūkā šī aptauja jau ir aizpildīta.')->assertDontSee('id="survey-form"', false);

        $invitation = $this->invitation();
        $familyUrl = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $familyId = $this->get($familyUrl)->assertOk()->assertViewHas('submitted', true)
            ->assertSee('id="survey-form"', false)->viewData('submissionId');
        $this->assertNotSame($id, $familyId);
        $nextFamilyId = $this->get($familyUrl)->assertOk()->viewData('submissionId');
        $this->assertNotSame($familyId, $nextFamilyId);
        $publicForm = $this->get($url)->assertOk()->assertViewHas('submitted', true)
            ->assertDontSee('id="survey-form"', false);
        $publicId = $publicForm->viewData('submissionId');
        $this->assertNotSame($id, $publicId);
        $this->assertNotSame($familyId, $publicId);
        $this->assertNotSame($nextFamilyId, $publicId);
        $this->get($url)->assertOk()->assertViewHas('submitted', true)->assertViewHas('submissionId', $publicId);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_malformed_family_roles_do_not_consume_the_submission_id_or_claim_participation(): void
    {
        $invitation = $this->invitation();
        $url = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');

        foreach ([null, 'parent', ['mother']] as $role) {
            $this->postJson($url, $this->payload(extra: ['submission_id' => $id, 'role' => $role]))
                ->assertUnprocessable()->assertJsonValidationErrors('role');
            $this->assertDatabaseCount('survey_responses', 0);
            $this->assertDatabaseCount('family_participations', 0);
            $this->assertSame($id, session('survey_submission_ids.'.$this->survey->id));
            $nextId = $this->get($url)->assertOk()->assertViewHas('submitted', false)->viewData('submissionId');
            $this->assertNotSame($id, $nextId);
            $id = $nextId;
        }

        $this->postJson($url, $this->payload(extra: ['submission_id' => $id, 'role' => 'mother']))->assertOk();
        $this->assertDatabaseHas('survey_responses', ['id' => $id]);
        $this->assertDatabaseHas('family_participations', ['invitation_id' => $invitation->id, 'role' => 'mother']);
    }

    public function test_legacy_invitation_with_wrong_school_is_unusable(): void
    {
        $invitation = $this->invitation();
        $other = $this->otherSurvey();
        DB::table('family_invitations')->where('id', $invitation->id)->update(['school_id' => $other->school_id]);
        $this->get(route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]))->assertNotFound();
    }

    public function test_stale_family_form_cannot_silently_discard_another_parent_response(): void
    {
        $invitation = $this->invitation();
        $url = route('surveys.family', ['slug' => $this->survey->slug, 'token' => $invitation->token]);
        $form = $this->get($url)->assertOk();
        $id = $form->viewData('submissionId');
        $nextId = $this->get($url)->assertOk()->viewData('submissionId');
        $this->assertTrue(Str::isUuid($id));
        $this->assertTrue(Str::isUuid($nextId));
        $this->assertNotSame($id, $nextId);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
        $this->getJson(route('surveys.family.session', ['slug' => $this->survey->slug, 'token' => $invitation->token]), [
            'X-Requested-With' => 'XMLHttpRequest', 'X-Survey-Resume' => $form->viewData('submissionResumeToken'),
        ])->assertOk()->assertJsonPath('submission_id', $id)->assertJsonPath('submitted', false);
        $mother = $this->payload(['q4' => 'Mother private answer'], ['submission_id' => $id, 'role' => 'mother']);
        $this->postJson($url, $mother)->assertOk();
        $this->postJson($url, $this->payload(extra: ['submission_id' => $id, 'role' => 'father']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson($url, $mother)->assertOk();
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
        $newId = $this->get($url)->assertOk()->viewData('submissionId');
        $this->assertNotSame($id, $newId);
        $this->assertNotSame($nextId, $newId);
        $this->postJson($url, $this->payload(extra: ['submission_id' => $newId, 'role' => 'mother']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
        $this->postJson($url, $this->payload(['q4' => 'Father private answer'], ['submission_id' => $newId, 'role' => 'father']))->assertOk();
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);
        $this->assertSame(['q4' => ['value' => 'Mother private answer', 'other' => null]], $this->survey->responses()->findOrFail($id)->answers);
        $this->assertSame(['q4' => ['value' => 'Father private answer', 'other' => null]], $this->survey->responses()->findOrFail($newId)->answers);
    }

    public function test_other_choice_is_rendered_when_enabled_without_an_explicit_option(): void
    {
        $this->get(route('surveys.show', $this->survey->slug))->assertOk()
            ->assertSee('value="other"', false)->assertSee('name="other_answers[q1]"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_draft_is_not_public_and_closed_survey_shows_unavailable_state(): void
    {
        $draft = $this->survey->duplicate();
        $this->get(route('surveys.show', ['slug' => $draft->slug]))->assertNotFound();
        $this->get(route('surveys.thanks', ['slug' => $draft->slug]))->assertNotFound();
        $this->survey->update(['status' => 'closed']);
        $this->get(route('surveys.show', ['slug' => $this->survey->slug]))->assertOk()
            ->assertViewHas('survey', fn ($survey) => ! $survey->isAcceptingResponses());
        $payload = $this->payload();
        $this->withSession(['survey_submission_ids' => [$this->survey->id => $payload['submission_id']]])
            ->postJson(route('surveys.submit', ['slug' => $this->survey->slug]), $payload)->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_index_lists_only_open_unexpired_mikausis_surveys(): void
    {
        $this->otherSurvey();
        $draft = $this->survey->duplicate();
        $this->get(route('surveys.index'))->assertOk()
            ->assertViewHas('surveys', fn ($surveys) => $surveys->modelKeys() === [$this->survey->id]);
        $this->survey->update(['closes_at' => now()->subMinute()]);
        $this->get(route('surveys.index'))->assertOk()->assertViewHas('surveys', fn ($surveys) => $surveys->isEmpty());
    }
}
