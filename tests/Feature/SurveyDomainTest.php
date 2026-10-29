<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Models\FamilyParticipation;
use App\Models\School;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SurveyDomainTest extends SurveyTestCase
{
    public function test_response_and_participation_tables_cannot_link_answers_to_a_family_or_time(): void
    {
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'invitation_id', 'role'], Schema::getColumnListing('family_participations'));
        $this->assertFalse((new SurveyResponse)->timestamps);
        $this->assertFalse((new FamilyParticipation)->timestamps);
    }

    public function test_invitation_token_is_random_encrypted_and_resolvable_by_hash(): void
    {
        $first = $this->invitation();
        $second = $this->invitation();
        $this->assertSame(48, strlen($first->token));
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(hash('sha256', $first->token), $first->token_hash);
        $this->assertNotSame($first->token, DB::table('family_invitations')->where('id', $first->id)->value('token'));
        $this->assertSame($first->token, $first->fresh()->token);
        $this->assertArrayNotHasKey('token', $first->toArray());
        $this->assertArrayNotHasKey('label', $first->toArray());
    }

    public function test_invitation_school_must_match_its_survey(): void
    {
        $other = $this->otherSurvey();
        $this->expectException(ValidationException::class);
        FamilyInvitation::create(['school_id' => $other->school_id, 'survey_id' => $this->survey->id, 'label' => 'Wrong tenant']);
    }

    public function test_invitation_cannot_be_reassigned_after_creation(): void
    {
        $invitation = $this->invitation();
        $other = $this->otherSurvey();
        $this->expectException(ValidationException::class);
        $invitation->update(['school_id' => $other->school_id, 'survey_id' => $other->id]);
    }

    public function test_tenant_scopes_exclude_foreign_and_inconsistent_nested_invitations(): void
    {
        $own = $this->invitation();
        $other = $this->otherSurvey();
        $foreign = $this->invitation($other);
        $own->participations()->create(['role' => 'mother']);
        $foreign->participations()->create(['role' => 'father']);
        $user = User::factory()->create();
        $user->school_id = $this->school->id;
        $user->save();

        $this->assertSame([$this->survey->id], Survey::accessibleTo($user)->pluck('id')->all());
        $this->assertSame([$own->id], FamilyInvitation::accessibleTo($user)->pluck('id')->all());
        $this->assertSame(1, FamilyParticipation::accessibleTo($user)->count());
        DB::table('family_invitations')->where('id', $foreign->id)->update(['school_id' => $this->school->id]);
        $this->assertSame([$own->id], FamilyInvitation::accessibleTo($user)->pluck('id')->all());
        $this->assertSame(1, FamilyParticipation::accessibleTo($user)->count());
        $user->school_id = null;
        $this->assertSame(0, Survey::accessibleTo($user)->count());
    }

    public function test_published_content_can_be_edited_and_removed_questions_are_archived_while_a_copy_is_a_fresh_draft(): void
    {
        $this->assertNotNull($this->survey->published_at);
        $this->survey->responses()->create(['answers' => ['q1' => ['value' => 'yes', 'other' => null]]]);
        $this->invitation()->participations()->create(['role' => 'mother']);
        $copy = $this->survey->duplicate();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->closes_at);
        $this->assertNotSame($this->survey->slug, $copy->slug);
        $this->assertSame($this->survey->questions, $copy->questions);
        $this->assertSame($this->survey->sections, $copy->sections);
        $this->assertSame(0, $copy->responses()->count());
        $this->assertSame(0, $copy->invitations()->count());
        $copy->update(['questions' => [], 'title' => 'Editable copy']);

        $questions = $this->survey->questions;
        $removed = array_pop($questions);
        $questions[0]['title'] = 'Corrected question';
        $questions[0]['options'][0]['label'] = 'Corrected answer';
        $this->survey->update(['questions' => $questions, 'title' => 'Edited published survey']);

        $saved = $this->survey->fresh();
        $this->assertSame($questions, $saved->questions);
        $this->assertSame([$removed], $saved->archived_questions);
        $this->assertSame('Edited published survey', $saved->title);
        $this->assertSame('open', $saved->status);
        $this->assertSame(1, $saved->responses()->count());
        $this->assertSame(1, $saved->invitations()->count());
    }

    public function test_stale_draft_model_can_edit_content_while_preserving_authoritative_publication_metadata(): void
    {
        $draft = $this->survey->duplicate();
        $fresh = $draft->fresh();
        $fresh->update(['status' => 'open']);
        $questions = $draft->questions;
        $removed = array_pop($questions);
        $draft->update([
            'title' => 'Edited from stale draft', 'questions' => $questions,
        ]);

        $saved = $draft->fresh();
        $this->assertSame('Edited from stale draft', $saved->title);
        $this->assertSame($questions, $saved->questions);
        $this->assertSame([$removed], $saved->archived_questions);
        $this->assertSame('open', $saved->status);
        $this->assertSame($fresh->school_id, $saved->school_id);
        $this->assertSame($fresh->slug, $saved->slug);
        $this->assertTrue($fresh->published_at->equalTo($saved->published_at));
    }

    public function test_published_survey_cannot_return_to_draft(): void
    {
        $this->expectException(ValidationException::class);
        $this->survey->update(['status' => 'draft']);
    }

    public function test_published_survey_cannot_be_deleted(): void
    {
        $this->expectException(ValidationException::class);
        $this->survey->delete();
    }

    public function test_response_bearing_draft_cannot_be_deleted(): void
    {
        $draft = $this->survey->duplicate();
        $draft->responses()->create(['answers' => ['q1' => ['value' => 'yes', 'other' => null]]]);
        $this->expectException(ValidationException::class);
        $draft->delete();
    }

    public function test_unused_draft_can_be_deleted(): void
    {
        $draft = $this->survey->duplicate();
        $id = $draft->id;
        $draft->delete();
        $this->assertDatabaseMissing('surveys', ['id' => $id]);
    }

    public function test_accepting_responses_obeys_exact_deadline(): void
    {
        $this->freezeTime();
        $this->assertTrue($this->survey->isAcceptingResponses());
        $this->survey->closes_at = now()->addSecond();
        $this->assertTrue($this->survey->isAcceptingResponses());
        $this->survey->closes_at = now();
        $this->assertFalse($this->survey->isAcceptingResponses());
    }

    public function test_seed_is_real_content_only_and_reruns_preserve_edits(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seeded = Survey::where('slug', 'oktobra-vecaku-aptauja-4m7p2r8k6v9x3q5n')->firstOrFail();
        $this->assertSame('draft', $seeded->status);
        $source = json_decode(file_get_contents(database_path('seed-data/october-survey.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($source['questions'], $seeded->questions);
        $seeded->update(['title' => 'Edited draft']);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame('Edited draft', $seeded->fresh()->title);
        $this->assertSame(2, Survey::count());
        $this->assertSame(1, School::count());
        $this->assertSame(0, User::count());
        $this->assertDatabaseCount('survey_responses', 0);
    }
}
