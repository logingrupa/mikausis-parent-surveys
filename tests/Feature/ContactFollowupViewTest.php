<?php

namespace Tests\Feature;

use App\Models\Survey;
use DOMDocument;
use DOMXPath;

class ContactFollowupViewTest extends SurveyTestCase
{
    private function contactSurvey(): Survey
    {
        $survey = $this->survey->duplicate();
        $questions = $survey->questions;
        $questions[0]['title'] = '</script><script>alert(1)</script>';
        $questions[0]['contact'] = [
            'prompt' => 'Contact', 'purpose' => 'Follow up', 'trigger_values' => ['yes'],
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'tel', 'required' => true],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'required' => true],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'required' => false],
            ],
        ];
        $survey->update(['questions' => $questions, 'status' => 'open']);

        return $survey;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_thanks_bootstraps_only_current_contact_definitions_with_safe_json_and_default_hidden_opt_in(): void
    {
        $survey = $this->contactSurvey();
        $html = $this->get(route('surveys.thanks', $survey->slug))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//form[@id="contact-followup-form"][@hidden]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="contact-followup-form"]//input[@type="checkbox"]')->length);
        $this->assertSame(0, $xpath->query('//form[@id="contact-followup-form"]//input[@type="checkbox"][@checked]')->length);
        $this->assertSame(0, $xpath->query('//form[@id="contact-followup-form"]//ul[@data-followup-questions]/li')->length);
        $this->assertSame(0, $xpath->query('//form[@id="contact-followup-form"]//input[@name="submission_id"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="contact-followup-form"]//ul[@data-shared-contact-activities][@hidden]')->length);
        $this->assertSame($survey->formDefinitionToken(), $xpath->query('//form[@id="contact-followup-form"]/@data-contact-definition')->item(0)->nodeValue);
        $json = $xpath->query('//script[@id="contact-followup-definitions"]')->item(0)->textContent;
        $definitions = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $definitions);
        $this->assertSame($survey->questions[0]['title'], $definitions[0]['title']);
        $this->assertSame(['text', 'email', 'tel'], array_column($definitions[0]['fields'], 'type'));
        $this->assertSame(['yes'], $definitions[0]['triggerValues']);
        $this->assertArrayNotHasKey('submission_id', $definitions[0]);
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_compact_thanks_panel_precedes_separate_contact_section_and_secondary_actions(): void
    {
        $survey = $this->contactSurvey();
        $html = $this->get(route('surveys.thanks', $survey->slug))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $panel = '//section[contains(concat(" ", normalize-space(@class), " "), " thanks-panel ")]';

        $this->assertSame(0, $xpath->query($panel.'//form')->length);
        $this->assertSame(1, $xpath->query($panel.'/following-sibling::form[@id="contact-followup-form"]')->length);
        $this->assertSame(1, $xpath->query($panel.'/following-sibling::div[@data-followup-status][@role="status"][@tabindex="-1"][@hidden]')->length);
        $this->assertSame('Kontaktinformācija saglabāta.', $xpath->query('//div[@data-followup-status]/strong')->item(0)->textContent);
        $this->assertSame('Kontaktinformācija ir saistīta tikai ar šo aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.', $xpath->query('//div[@data-followup-status]/p')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//div[@data-followup-status]/following-sibling::div[contains(@class, "thanks-actions")]//form[@data-survey-restart]')->length);
        $this->assertLessThan(strpos($html, 'class="thanks-actions"'), strpos($html, 'id="contact-followup-form"'));
    }

    public function test_survey_and_standalone_fields_use_builtin_types_without_rewriting_saved_definitions(): void
    {
        $survey = $this->contactSurvey();
        $original = $survey->questions;
        $html = $this->get(route('surveys.show', $survey->slug))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        foreach (['name' => 'text', 'email' => 'email', 'phone' => 'tel'] as $key => $type) {
            $this->assertSame(1, $xpath->query('//input[@data-contact-field="'.$key.'"][@type="'.$type.'"][@data-contact-type="'.$type.'"]')->length);
        }
        $this->get(route('question-contacts.show', ['slug' => $survey->slug, 'question' => 'q1']))
            ->assertOk()->assertSee('type="email" name="fields[email]"', false)
            ->assertSee('type="tel" name="fields[phone]"', false);
        $this->assertSame($original, $survey->fresh()->questions);
        $this->assertStringContainsString('js/contact-followup-state.js', $html);
        $this->assertStringContainsString('js/contact-collector.js', $html);
    }

    public function test_closed_and_non_contact_surveys_do_not_offer_new_followup_forms(): void
    {
        $this->get(route('surveys.thanks', $this->survey->slug))->assertOk()
            ->assertDontSee('id="contact-followup-form"', false);
        $survey = $this->contactSurvey();
        $survey->update(['status' => 'closed']);
        $this->get(route('surveys.thanks', $survey->slug))->assertOk()
            ->assertDontSee('id="contact-followup-form"', false)
            ->assertDontSee('id="contact-followup-definitions"', false);
    }
}
