<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Survey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $school = School::query()->firstOrCreate(['slug' => 'mikausis'], ['name' => 'PPII “Mikausis”']);
        $content = json_decode(File::get(database_path('seed-data/october-survey.json')), true, 512, JSON_THROW_ON_ERROR);

        // Source content is a starting point; rerunning seeds must preserve edits.
        Survey::query()->firstOrCreate(['slug' => 'oktobra-vecaku-aptauja-4m7p2r8k6v9x3q5n'], [
            'school_id' => $school->id,
            'title' => $content['title'],
            'description' => $content['description'],
            'sections' => $content['sections'],
            'questions' => $content['questions'],
            'estimated_minutes' => $content['estimated_minutes'] ?? 8,
            'status' => app()->environment('local') ? 'open' : 'draft',
        ]);
    }
}
