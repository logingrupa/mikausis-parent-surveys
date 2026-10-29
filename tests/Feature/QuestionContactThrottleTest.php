<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Support\Str;

class QuestionContactThrottleTest extends SurveyTestCase
{
    private function contactSurvey(int $count): Survey
    {
        return Survey::create([
            'school_id' => $this->school->id,
            'title' => 'Aktivitāšu kontakti',
            'sections' => $this->survey->sections,
            'questions' => array_map(fn (int $index) => [
                'key' => 'activity_'.$index,
                'type' => 'single',
                'title' => 'Aktivitāte '.$index,
                'required' => false,
                'options' => [['value' => 'yes', 'label' => 'Piedalīšos']],
                'contact' => [
                    'prompt' => 'Atstāt kontaktus',
                    'purpose' => 'Saziņai par aktivitāti',
                    'trigger_values' => ['yes'],
                    'fields' => [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true]],
                ],
            ], range(1, $count)),
            'status' => 'open',
        ]);
    }

    public function test_one_submission_can_send_contacts_for_all_one_hundred_supported_questions(): void
    {
        $this->assertSame('array', config('cache.default'));
        $survey = $this->contactSurvey(100);
        $answers = array_fill_keys(array_column($survey->questions, 'key'), 'yes');
        $submissionId = $this->get(route('surveys.show', $survey->slug))->assertOk()->viewData('submissionId');
        $this->postJson(route('surveys.submit', $survey->slug), $this->payload($answers, ['submission_id' => $submissionId]))
            ->assertOk()->assertHeader('X-RateLimit-Limit', '30');

        foreach ($survey->questions as $question) {
            $this->postJson(route('question-contacts.store', ['slug' => $survey->slug, 'question' => $question['key']]), [
                'contact_id' => (string) Str::uuid(),
                'consent' => true,
                'answer' => 'yes',
                'fields' => ['email' => 'synthetic@example.test'],
            ])->assertOk()->assertExactJson(['saved' => true])->assertHeader('X-RateLimit-Limit', '150');
        }

        $this->assertSame(1, $survey->responses()->count());
        $this->assertSame(100, $survey->questionAnswers()->count());
        $this->assertSame(100, $survey->questionContacts()->count());
        $this->assertSame([], $survey->responses()->firstOrFail()->answers);
    }

    public function test_contact_budget_is_bounded_and_independent_from_the_main_submission_budget(): void
    {
        $this->assertSame('array', config('cache.default'));
        $survey = $this->contactSurvey(1);
        $url = route('question-contacts.store', ['slug' => $survey->slug, 'question' => 'activity_1']);
        $contact = ['contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes', 'fields' => ['email' => 'synthetic@example.test']];
        foreach (range(1, 150) as $attempt) {
            $this->postJson($url, $contact)->assertOk();
        }
        $this->postJson($url, $contact)->assertTooManyRequests()->assertHeader('Retry-After');
        $submissionId = $this->get(route('surveys.show', $survey->slug))->assertOk()->viewData('submissionId');
        $main = $this->payload(['activity_1' => 'yes'], ['submission_id' => $submissionId]);
        foreach (range(1, 30) as $attempt) {
            $this->postJson(route('surveys.submit', $survey->slug), $main)->assertOk();
        }
        $this->postJson(route('surveys.submit', $survey->slug), $main)->assertTooManyRequests();
        $this->assertSame(1, $survey->questionContacts()->count());
        $this->assertSame(1, $survey->responses()->count());
    }
}
