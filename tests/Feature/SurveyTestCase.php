<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Models\School;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class SurveyTestCase extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected Survey $survey;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Mikausis', 'slug' => 'mikausis']);
        $this->survey = Survey::create([
            'school_id' => $this->school->id,
            'title' => 'Vecāku aptauja',
            'sections' => [['key' => 'all', 'title' => 'Jautājumi']],
            'questions' => [
                ['key' => 'q1', 'type' => 'single', 'title' => 'Single', 'required' => false, 'allow_other' => true, 'options' => [['value' => 'yes', 'label' => 'Jā'], ['value' => 'no', 'label' => 'Nē']]],
                ['key' => 'q2', 'type' => 'multiple', 'title' => 'Multiple', 'required' => false, 'allow_other' => true, 'exclusive_values' => ['no'], 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'no', 'label' => 'Nē']]],
                ['key' => 'q3', 'type' => 'text', 'title' => 'Conditional', 'required' => false, 'condition' => ['question' => 'q1', 'values' => ['yes']]],
                ['key' => 'q4', 'type' => 'text', 'title' => 'Details', 'required' => false],
            ],
            'status' => 'open',
        ]);
    }

    protected function payload(array $answers = ['q1' => 'yes'], array $extra = []): array
    {
        return array_replace(['submission_id' => (string) Str::uuid(), 'answers' => $answers], $extra);
    }

    protected function invitation(?Survey $survey = null): FamilyInvitation
    {
        $survey ??= $this->survey;

        return FamilyInvitation::create(['school_id' => $survey->school_id, 'survey_id' => $survey->id, 'label' => 'Privāta ģimene']);
    }

    protected function otherSurvey(): Survey
    {
        $school = School::create(['name' => 'Another school', 'slug' => 'school-'.Str::random(12)]);

        return Survey::create(['school_id' => $school->id, 'title' => 'Other survey', 'questions' => $this->survey->questions, 'sections' => [], 'status' => 'open']);
    }
}
