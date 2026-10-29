<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Models\School;
use App\Models\Survey;
use App\Services\SurveyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SurveyReportTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    public static function responseThresholds(): array
    {
        return [
            'no responses' => [0, true],
            'four responses' => [4, true],
            'five responses' => [5, false],
        ];
    }

    #[DataProvider('responseThresholds')]
    public function test_question_results_require_five_responses(int $count, bool $suppressed): void
    {
        $survey = $this->createSurvey();

        for ($index = 0; $index < $count; $index++) {
            $survey->responses()->create([
                'answers' => ['single' => ['value' => 'yes', 'other' => null]],
            ]);
        }

        $report = app(SurveyReportService::class)->build($survey);

        $this->assertSame($count, $report['response_count']);
        $this->assertSame(5, $report['minimum_responses']);
        $this->assertSame($suppressed, $report['suppressed']);
        $this->assertSame(0, $report['invitations_count']);
        $this->assertSame(0, $report['participation_count']);

        if ($suppressed) {
            $this->assertSame([], $report['questions']);
        } else {
            $this->assertCount(count($survey->questions), $report['questions']);
            $this->assertSame(5, $report['questions'][0]['answered']);
            $this->assertSame(0, $report['questions'][0]['skipped']);
        }
    }

    public function test_counts_use_answered_respondents_and_return_only_anonymous_question_results(): void
    {
        $survey = $this->createSurvey();
        $rows = [
            [
                'single' => ['value' => 'yes', 'other' => 'Unselected detail must stay hidden'],
                'multiple' => ['value' => ['a', 'b', 'a'], 'other' => null],
                'text' => ['value' => ' First comment ', 'other' => null],
                'existing_other' => ['value' => 'other', 'other' => 'Existing other detail'],
            ],
            [
                'single' => ['value' => 'no', 'other' => null],
                'multiple' => ['value' => ['a', 'other'], 'other' => ' More choices '],
                'text' => ['value' => 'Second comment', 'other' => null],
            ],
            [
                'single' => ['value' => 'other', 'other' => ' Different answer '],
                'multiple' => ['value' => ['b'], 'other' => 'Unselected multiple detail'],
                'text' => ['value' => '   ', 'other' => null],
            ],
            [
                'single' => ['value' => '', 'other' => 'Orphan detail'],
                'multiple' => ['value' => [], 'other' => null],
                'text' => ['value' => '', 'other' => null],
            ],
            [],
        ];
        $responseIds = [];

        foreach ($rows as $answers) {
            $responseIds[] = $survey->responses()->create(['answers' => $answers])->getKey();
        }

        // Neither another survey's responses nor a previously loaded relation may affect the result.
        $otherSurvey = $this->createSurvey();
        $otherSurvey->responses()->create([
            'answers' => ['text' => ['value' => 'Another survey secret', 'other' => null]],
        ]);
        $survey->setRelation('responses', collect());

        $report = app(SurveyReportService::class)->build($survey);
        $questions = array_column($report['questions'], null, 'key');

        $this->assertSame(5, $report['response_count']);
        $this->assertFalse($report['suppressed']);
        $this->assertSame(['single', 'multiple', 'text', 'existing_other', 'unanswered'], array_keys($questions));

        $single = $questions['single'];
        $this->assertSame('Single choice', $single['title']);
        $this->assertSame('single', $single['type']);
        $this->assertSame(3, $single['answered']);
        $this->assertSame(2, $single['skipped']);
        $this->assertSame(['yes', 'no', 'other'], array_column($single['options'], 'value'));
        $this->assertSame(['Yes', 'No', 'Cits'], array_column($single['options'], 'label'));
        $this->assertSame([1, 1, 1], array_column($single['options'], 'count'));
        $this->assertEquals([33.33, 33.33, 33.33], array_column($single['options'], 'percent'));
        $this->assertSame(['Different answer'], $single['texts']);

        $multiple = $questions['multiple'];
        $this->assertSame(3, $multiple['answered']);
        $this->assertSame(2, $multiple['skipped']);
        $this->assertSame(['a', 'b', 'other'], array_column($multiple['options'], 'value'));
        $this->assertSame([2, 2, 1], array_column($multiple['options'], 'count'));
        $this->assertEquals([66.67, 66.67, 33.33], array_column($multiple['options'], 'percent'));
        $this->assertGreaterThan(100, array_sum(array_column($multiple['options'], 'percent')));
        $this->assertSame(['More choices'], $multiple['texts']);

        $text = $questions['text'];
        $this->assertSame(2, $text['answered']);
        $this->assertSame(3, $text['skipped']);
        $this->assertSame([], $text['options']);
        $this->assertEqualsCanonicalizing(['First comment', 'Second comment'], $text['texts']);

        $existing = $questions['existing_other'];
        $this->assertSame(['yes', 'other'], array_column($existing['options'], 'value'));
        $this->assertSame('Custom other label', $existing['options'][1]['label']);
        $this->assertSame(['Existing other detail'], $existing['texts']);

        $unanswered = $questions['unanswered'];
        $this->assertSame(0, $unanswered['answered']);
        $this->assertSame(5, $unanswered['skipped']);
        $this->assertSame(['yes'], array_column($unanswered['options'], 'value'));
        $this->assertSame(0, $unanswered['options'][0]['count']);
        $this->assertEquals(0, $unanswered['options'][0]['percent']);
        $this->assertSame([], $unanswered['texts']);

        $this->assertSame([
            'response_count', 'minimum_responses', 'suppressed', 'questions',
            'invitations_count', 'participation_count',
        ], array_keys($report));

        foreach ($report['questions'] as $question) {
            $this->assertSame(['key', 'title', 'type', 'answered', 'skipped', 'options', 'texts'], array_keys($question));

            foreach ($question['options'] as $option) {
                $this->assertSame(['value', 'label', 'count', 'percent'], array_keys($option));
            }
        }

        $this->assertNoIdentityOrTimestampKeys($report);
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        foreach ($responseIds as $id) {
            $this->assertStringNotContainsString((string) $id, $json);
        }

        $this->assertStringNotContainsString('Another survey secret', $json);
    }

    public function test_invitation_and_participation_counts_are_scoped_to_both_survey_and_school(): void
    {
        $survey = $this->createSurvey();
        $sameSchoolSurvey = $this->createSurvey($survey->school_id);
        $otherSchoolSurvey = $this->createSurvey();

        $first = $this->createInvitation($survey, $survey->school_id);
        $second = $this->createInvitation($survey, $survey->school_id);
        $this->createInvitation($survey, $survey->school_id);
        $first->participations()->create(['role' => 'mother']);
        $first->participations()->create(['role' => 'father']);
        $second->participations()->create(['role' => 'mother']);

        foreach ([
            [$sameSchoolSurvey, $survey->school_id],
            [$otherSchoolSurvey, $otherSchoolSurvey->school_id],
        ] as [$unrelatedSurvey, $schoolId]) {
            $invitation = $this->createInvitation($unrelatedSurvey, $schoolId);
            $invitation->participations()->create(['role' => 'mother']);
        }

        // Simulate an inconsistent legacy row without bypassing tenant checks in production.
        $mismatched = $this->createInvitation($otherSchoolSurvey, $otherSchoolSurvey->school_id);
        FamilyInvitation::query()->whereKey($mismatched->getKey())->update(['survey_id' => $survey->getKey()]);
        $mismatched->participations()->create(['role' => 'guardian']);

        $survey->setRelation('invitations', collect());
        $report = app(SurveyReportService::class)->build($survey);

        $this->assertSame(3, $report['invitations_count']);
        $this->assertSame(3, $report['participation_count']);
        $this->assertSame(0, $report['response_count']);
        $this->assertTrue($report['suppressed']);
        $this->assertSame([], $report['questions']);
        $this->assertNoIdentityOrTimestampKeys($report);
    }

    private function createSurvey(?int $schoolId = null): Survey
    {
        $schoolId ??= School::create(['name' => 'Test school', 'slug' => 'school-'.Str::uuid()])->getKey();

        return Survey::create([
            'school_id' => $schoolId,
            'title' => 'Test survey',
            'slug' => 'survey-'.Str::uuid(),
            'sections' => [],
            'questions' => [
                [
                    'key' => 'single', 'title' => 'Single choice', 'type' => 'single',
                    'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']],
                    'allow_other' => true,
                ],
                [
                    'key' => 'multiple', 'title' => 'Multiple choice', 'type' => 'multiple',
                    'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
                    'allow_other' => true,
                ],
                ['key' => 'text', 'title' => 'Comments', 'type' => 'text', 'options' => [], 'allow_other' => false],
                [
                    'key' => 'existing_other', 'title' => 'Existing other', 'type' => 'single',
                    'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'other', 'label' => 'Custom other label']],
                    'allow_other' => true,
                ],
                [
                    'key' => 'unanswered', 'title' => 'Unanswered', 'type' => 'single',
                    'options' => [['value' => 'yes', 'label' => 'Yes']], 'allow_other' => false,
                ],
            ],
            'status' => 'draft',
        ]);
    }

    private function createInvitation(Survey $survey, int $schoolId): FamilyInvitation
    {
        return $survey->invitations()->create([
            'school_id' => $schoolId,
            'label' => 'Test family',
        ]);
    }

    private function assertNoIdentityOrTimestampKeys(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $this->assertDoesNotMatchRegularExpression('/(^id$|_id$|_at$|timestamp|respondent|^responses$|^answers$)/i', $key);
            }

            if (is_array($value)) {
                $this->assertNoIdentityOrTimestampKeys($value);
            }
        }
    }
}
