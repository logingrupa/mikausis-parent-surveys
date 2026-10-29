<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('sections');
            $table->json('questions');
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->default(8);
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('survey_id')->constrained()->restrictOnDelete();
            $table->json('answers');
        });
        Schema::create('family_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->timestamps();
            $table->index(['school_id', 'survey_id']);
        });
        Schema::create('family_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('family_invitations')->cascadeOnDelete();
            $table->enum('role', ['mother', 'father', 'guardian']);
            $table->unique(['invitation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_participations');
        Schema::dropIfExists('family_invitations');
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('surveys');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn('is_admin');
        });
        Schema::dropIfExists('schools');
    }
};
