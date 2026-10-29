<?php

namespace Tests\Feature;

use App\Models\Survey;
use DOMDocument;
use DOMXPath;

class RespondentFormTest extends SurveyTestCase
{
    private function configurableSurvey(array $fields): Survey
    {
        return Survey::create([
            'school_id' => $this->school->id,
            'title' => 'Izvēles respondenta dati',
            'sections' => $this->survey->sections,
            'questions' => $this->survey->questions,
            'allow_identified_responses' => true,
            'respondent_fields' => $fields,
            'status' => 'open',
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_block_is_absent_for_anonymous_only_survey(): void
    {
        $this->get(route('surveys.show', $this->survey->slug))->assertOk()
            ->assertDontSee('id="respondent-anonymous"', false)
            ->assertDontSee('name="respondent[', false);
    }

    public function test_added_block_defaults_to_anonymous_and_uses_only_the_configured_fields(): void
    {
        $survey = $this->configurableSurvey([
            ['key' => 'email', 'label' => 'Adrese saziņai', 'type' => 'email', 'required' => true],
            ['key' => 'detail_11111111111141118111111111111111', 'label' => 'Kā jūs uzrunāt?', 'type' => 'text', 'required' => false],
        ]);

        $response = $this->get(route('surveys.show', $survey->slug))->assertOk()
            ->assertSee('Adrese saziņai')->assertSee('Kā jūs uzrunāt?')
            ->assertSee('kopā ar jūsu atbildēm')->assertDontSee('Vārds un uzvārds');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@id="respondent-anonymous"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="survey-form"][@novalidate]')->length);
        $response->assertSee('Ja atstājat atzīmi “Anonīmi”')->assertSee('Ja noņemat atzīmi un norādāt savus datus');
        $this->assertSame(1, $xpath->query('//input[@name="anonymous"][@type="hidden"][@value="0"]')->length);
        $this->assertSame(1, $xpath->query('//input[@id="respondent-email"][@type="email"][@maxlength="254"]')->length);
        $this->assertSame(0, $xpath->query('//input[@data-respondent-input][@required]')->length);
    }

    public function test_named_validation_recovers_values_and_links_to_configured_field_without_session_flash(): void
    {
        $survey = $this->configurableSurvey([
            ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
        ]);
        $url = route('surveys.show', $survey->slug);
        $submissionId = $this->get($url)->viewData('submissionId');
        $name = 'Sintētiskais vecāks <script>test</script>';

        $response = $this->post($url, $this->payload(['q1' => 'yes'], [
            'submission_id' => $submissionId,
            'anonymous' => '0',
            'respondent' => ['name' => $name, 'email' => 'not-an-email'],
        ]))->assertUnprocessable()->assertSee($name)->assertDontSee($name, false)
            ->assertSee('href="#respondent-email"', false)
            ->assertSee('data-error-identity="respondent-email"', false)
            ->assertSessionMissing(['_old_input', 'respondent', 'respondent_name']);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//input[@id="respondent-anonymous"][@checked]')->length);
        $this->assertSame('not-an-email', $xpath->query('//input[@id="respondent-email"]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//input[@id="respondent-email"][@aria-invalid="true"][@required]')->length);
        $this->assertStringNotContainsString($name, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->get($url)->assertOk()->assertDontSee('Sintētiskais vecāks');
    }

    public function test_anonymous_invalid_answers_do_not_render_injected_identity_details(): void
    {
        $survey = $this->configurableSurvey([
            ['key' => 'name', 'label' => 'Vārds', 'type' => 'text', 'required' => true],
        ]);
        $url = route('surveys.show', $survey->slug);
        $submissionId = $this->get($url)->viewData('submissionId');
        $this->post($url, $this->payload(['q1' => 'wrong'], [
            'submission_id' => $submissionId,
            'anonymous' => '1',
            'respondent' => ['name' => 'DO NOT RENDER PRIVATE DATA'],
        ]))->assertUnprocessable()->assertDontSee('DO NOT RENDER PRIVATE DATA')
            ->assertViewHas('oldValues', fn ($old) => ! array_key_exists('respondent', $old));
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_family_role_is_reused_for_configured_role_field_even_after_malformed_role_validation(): void
    {
        $survey = $this->configurableSurvey([
            ['key' => 'role', 'label' => 'Ģimenes loma', 'type' => 'role', 'required' => true],
        ]);
        $invitation = $this->invitation($survey);
        $url = route('surveys.family', ['slug' => $survey->slug, 'token' => $invitation->token]);
        $response = $this->get($url)->assertOk()->assertSee('Ģimenes loma');
        $this->assertSame(0, $this->xpath($response->getContent())->query('//select[@name="respondent[role]"]')->length);
        $this->post($url, $this->payload(['q1' => 'yes'], [
            'submission_id' => $response->viewData('submissionId'),
            'anonymous' => '0',
            'role' => ['malformed'],
        ]))->assertUnprocessable()->assertSee('id="error-role"', false);
    }
}
