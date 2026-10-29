<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SurveyIdentityConflictTest extends SurveyTestCase
{
    private function contactQuestions(): array
    {
        $questions = $this->survey->questions;
        $questions[0]['contact'] = [
            'prompt' => 'Atstāt kontaktinformāciju', 'purpose' => 'Saziņai par talku.', 'trigger_values' => ['yes'],
            'fields' => [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true]],
        ];

        return $questions;
    }

    private function assertConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected identity configuration conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('questions.0.contact', $exception->errors());
        }
    }

    public function test_direct_eloquent_creation_cannot_save_or_publish_simultaneous_identity_blocks(): void
    {
        foreach (['draft', 'open'] as $status) {
            $this->assertConflict(fn () => Survey::create([
                'school_id' => $this->school->id, 'title' => 'Identitātes konflikts',
                'questions' => $this->contactQuestions(), 'sections' => $this->survey->sections,
                'allow_identified_responses' => true, 'status' => $status,
            ]));
        }
        $this->assertDatabaseCount('surveys', 1);
    }

    public function test_direct_draft_updates_cannot_enable_global_identity_or_add_contact_while_global_identity_enabled(): void
    {
        $draft = $this->survey->duplicate();
        $draft->update(['questions' => $this->contactQuestions()]);
        $this->assertConflict(fn () => $draft->update(['allow_identified_responses' => true]));
        $this->assertFalse($draft->fresh()->allow_identified_responses);
        $legacy = $this->survey->duplicate();
        $legacy->update(['allow_identified_responses' => true]);
        $this->assertConflict(fn () => $legacy->update(['questions' => $this->contactQuestions()]));
        $this->assertArrayNotHasKey('contact', $legacy->fresh()->questions[0]);
    }

    public function test_direct_publication_rejects_preexisting_conflict_inserted_without_model_events(): void
    {
        $draft = $this->survey->duplicate();
        DB::table('surveys')->where('id', $draft->id)->update([
            'questions' => json_encode($this->contactQuestions(), JSON_THROW_ON_ERROR), 'allow_identified_responses' => true,
        ]);
        $draft->refresh();
        $this->assertConflict(fn () => $draft->update(['status' => 'open']));
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->published_at);
    }

    public function test_stale_model_cannot_enable_global_identity_over_newly_added_contact_configuration(): void
    {
        $draft = $this->survey->duplicate();
        $stale = $draft->fresh();
        $draft->update(['questions' => $this->contactQuestions()]);
        $this->assertConflict(fn () => $stale->update(['allow_identified_responses' => true]));
        $this->assertFalse($draft->fresh()->allow_identified_responses);
    }

    public function test_stale_model_cannot_add_contact_over_newly_enabled_global_identity(): void
    {
        $draft = $this->survey->duplicate();
        $stale = $draft->fresh();
        $draft->update(['allow_identified_responses' => true]);
        $this->assertConflict(fn () => $stale->update(['questions' => $this->contactQuestions()]));
        $this->assertArrayNotHasKey('contact', $draft->fresh()->questions[0]);
    }

    public function test_legacy_global_identity_with_absent_or_null_contact_can_still_be_published(): void
    {
        foreach ([false, true] as $explicitNull) {
            $draft = $this->survey->duplicate();
            $questions = $draft->questions;
            if ($explicitNull) {
                $questions[0]['contact'] = null;
            }
            $draft->update(['questions' => $questions, 'allow_identified_responses' => true, 'status' => 'open']);
            $this->assertTrue($draft->fresh()->allow_identified_responses);
            $this->assertNotNull($draft->fresh()->published_at);
        }
    }
}
