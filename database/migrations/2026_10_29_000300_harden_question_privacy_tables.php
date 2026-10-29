<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        // Keep both rebuilds atomic, including when up() is called directly.
        $connection->transaction(function () use ($connection) {
            $grammar = $connection->getQueryGrammar();
            $surveys = $grammar->wrapTable('surveys');

            foreach (['question_answers', 'question_contacts'] as $table) {
                $original = $grammar->wrapTable($table);
                $replacement = $grammar->wrapTable($table.'_without_rowid');
                $columns = ['id', 'survey_id', 'question_key', 'answer'];
                $details = '';

                if ($table === 'question_contacts') {
                    $columns[] = 'details';
                    $details = ', "details" text not null';
                }

                // Equivalent to 000200's SQLite schema, without the hidden ordinal key.
                $connection->statement(<<<SQL
                    create table {$replacement} (
                        "id" varchar not null primary key,
                        "survey_id" integer not null,
                        "question_key" varchar not null,
                        "answer" text not null{$details},
                        foreign key ("survey_id") references {$surveys} ("id") on delete restrict
                    ) without rowid
                    SQL);

                // Copy raw stored bytes entirely inside SQLite: no casts or decryption.
                $columnList = $grammar->columnize($columns);
                $connection->statement("insert into {$replacement} ({$columnList}) select {$columnList} from {$original}");
                $connection->statement("drop table {$original}");
                $connection->statement("alter table {$replacement} rename to {$original}");

                $index = $grammar->wrap($connection->getTablePrefix().$table.'_survey_id_question_key_index');
                $connection->statement("create index {$index} on {$original} (\"survey_id\", \"question_key\")");
            }
        });
    }

    public function down(): void
    {
        // Restoring sequential linkage is unnecessary; 000200's down() drops both tables.
    }
};
