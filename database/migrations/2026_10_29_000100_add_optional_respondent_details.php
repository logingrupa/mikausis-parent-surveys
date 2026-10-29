<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->boolean('allow_identified_responses')->default(false);
            $table->json('respondent_fields')->nullable();
        });
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->text('respondent_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('respondent_details');
        });
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn('allow_identified_responses');
            $table->dropColumn('respondent_fields');
        });
    }
};
