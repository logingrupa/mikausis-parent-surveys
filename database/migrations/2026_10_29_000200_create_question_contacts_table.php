<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('survey_id')->constrained()->restrictOnDelete();
            $table->string('question_key', 80);
            $table->text('answer');
            $table->text('details');
            $table->index(['survey_id', 'question_key']);
        });
        Schema::create('question_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('survey_id')->constrained()->restrictOnDelete();
            $table->string('question_key', 80);
            $table->json('answer');
            $table->index(['survey_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answers');
        Schema::dropIfExists('question_contacts');
    }
};
