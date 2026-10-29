<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class QuestionPrivacyMigrationTest extends SurveyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        // Recreate the already-applied migration's old schema only in this memory DB.
        $legacy = require database_path('migrations/2026_10_29_000200_create_question_contacts_table.php');
        $legacy->down();
        $legacy->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_29_000300_harden_question_privacy_tables.php');
    }

    private function seedLegacyRows(): void
    {
        foreach ([$this->survey, $this->otherSurvey()] as $survey) {
            DB::table('survey_responses')->insert([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey->id,
                'answers' => '{ "q4" : { "value" : "Synthetic opinion", "other" : null } }',
                'respondent_details' => null,
            ]);

            foreach (['q1', 'q2'] as $key) {
                DB::table('question_answers')->insert([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey->id,
                    'question_key' => $key,
                    'answer' => "{\n  \"value\" : [\"a\", \"b\"], \"other\" : \"\\u0101\\/synthetic\"\n}",
                ]);
                DB::table('question_contacts')->insert([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey->id,
                    'question_key' => $key,
                    'answer' => Crypt::encryptString('{ "value" : "a", "other" : null }'),
                    'details' => Crypt::encryptString('{ "email" : "synthetic@example.invalid" }'),
                ]);
            }
        }
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function assertQueryFails(Closure $query, string $message): void
    {
        try {
            $query();
            $this->fail('Expected the database to reject the query.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_existing_uuids_raw_json_and_ciphertext_survive_the_rebuild_exactly(): void
    {
        $this->seedLegacyRows();
        $tables = ['question_answers', 'question_contacts', 'survey_responses'];
        $before = [];

        foreach ($tables as $table) {
            $before[$table] = $this->rows($table);
        }

        $this->migration()->up();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], $this->rows($table));
        }

        $ids = [];
        foreach (['question_answers', 'question_contacts'] as $table) {
            $this->assertDatabaseCount($table, 4);
            $this->assertSame(['id', 'survey_id', 'question_key', 'answer', ...($table === 'question_contacts' ? ['details'] : [])], Schema::getColumnListing($table));
            foreach ($this->rows($table) as $row) {
                $this->assertTrue(Str::isUuid($row['id']));
                $ids[] = $row['id'];
            }
            $this->assertSame(['q1', 'q2'], DB::table($table)->where('survey_id', $this->survey->id)->orderBy('question_key')->pluck('question_key')->all());
        }
        $ids = [...$ids, ...array_column($before['survey_responses'], 'id')];
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_all_hidden_rowid_aliases_and_ordinal_response_joins_are_unavailable(): void
    {
        $this->seedLegacyRows();

        foreach (['question_answers', 'question_contacts'] as $table) {
            $this->assertNotEmpty(DB::select("select q.id from {$table} as q join survey_responses as r on q.rowid = r.rowid"));
        }

        $this->migration()->up();
        $this->assertNotEmpty(DB::select('select rowid from survey_responses'));

        foreach (['question_answers', 'question_contacts'] as $table) {
            $definition = DB::scalar("select sql from sqlite_master where type = 'table' and name = ?", [$table]);
            $this->assertStringContainsString('without rowid', strtolower($definition));

            foreach (['rowid', '_rowid_', 'oid'] as $column) {
                // Unknown double-quoted SQLite identifiers can become string literals.
                foreach ([$column, $table.'.'.$column, 'q.'.$column] as $lookup) {
                    $from = str_starts_with($lookup, 'q.') ? "{$table} as q" : $table;
                    $this->assertQueryFails(fn () => DB::select("select {$lookup} from {$from}"), 'no such column');
                }
                $this->assertQueryFails(
                    fn () => DB::select("select q.id from {$table} as q join survey_responses as r on q.{$column} = r.rowid"),
                    'no such column',
                );
            }
        }
    }

    public function test_survey_foreign_keys_and_composite_indexes_are_preserved_and_enforced(): void
    {
        $before = [];
        foreach (['question_answers', 'question_contacts'] as $table) {
            $before[$table] = [Schema::getForeignKeys($table), Schema::getIndexes($table)];
        }

        $this->migration()->up();

        foreach (['question_answers', 'question_contacts'] as $table) {
            $this->assertSame($before[$table], [Schema::getForeignKeys($table), Schema::getIndexes($table)]);
            $index = collect(Schema::getIndexes($table))->firstWhere('name', $table.'_survey_id_question_key_index');
            $this->assertSame(['survey_id', 'question_key'], $index['columns']);
            $foreignKey = Schema::getForeignKeys($table)[0];
            $this->assertSame(['survey_id'], $foreignKey['columns']);
            $this->assertSame('surveys', $foreignKey['foreign_table']);
            $this->assertSame('restrict', $foreignKey['on_delete']);

            $survey = $this->otherSurvey();
            $row = ['id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'question_key' => 'q1', 'answer' => '{}'];
            if ($table === 'question_contacts') {
                $row['answer'] = Crypt::encryptString('{}');
                $row['details'] = Crypt::encryptString('{}');
            }
            DB::table($table)->insert($row);
            $this->assertQueryFails(fn () => DB::table('surveys')->where('id', $survey->id)->delete(), 'FOREIGN KEY constraint failed');
            $this->assertQueryFails(fn () => DB::table($table)->insert([...$row, 'id' => (string) Str::uuid(), 'survey_id' => -1]), 'FOREIGN KEY constraint failed');
            $this->assertQueryFails(fn () => DB::table($table)->insert($row), 'UNIQUE constraint failed');
        }
    }

    public function test_both_rebuilds_roll_back_if_a_foreign_key_blocks_the_second_table(): void
    {
        $this->seedLegacyRows();
        Schema::create('privacy_migration_blockers', function (Blueprint $table) {
            $table->uuid('contact_id');
            $table->foreign('contact_id')->references('id')->on('question_contacts')->restrictOnDelete();
        });
        DB::table('privacy_migration_blockers')->insert(['contact_id' => DB::table('question_contacts')->value('id')]);

        $schema = DB::select('select type, name, tbl_name, sql from sqlite_master order by type, name');
        $answers = $this->rows('question_answers');
        $contacts = $this->rows('question_contacts');
        $responses = $this->rows('survey_responses');
        $transactionLevel = DB::connection()->transactionLevel();

        $this->assertQueryFails(fn () => $this->migration()->up(), 'FOREIGN KEY constraint failed');

        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        $this->assertEquals($schema, DB::select('select type, name, tbl_name, sql from sqlite_master order by type, name'));
        $this->assertSame($answers, $this->rows('question_answers'));
        $this->assertSame($contacts, $this->rows('question_contacts'));
        $this->assertSame($responses, $this->rows('survey_responses'));
        foreach (['question_answers', 'question_contacts'] as $table) {
            $this->assertCount(4, DB::select("select rowid from {$table}"));
        }
    }
}
