<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\SchoolAccess;
use App\Models\Survey;
use App\Services\SurveyReportService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;

class SurveyReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SurveyResource::class;

    protected static ?string $breadcrumb = 'Atskaite';

    protected string $view = 'filament.resources.surveys.pages.survey-report';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Aptaujas atskaite';
    }

    protected function resolveRecord(int|string $key): Model
    {
        abort_unless(SchoolAccess::schoolId() !== null, 403);

        $survey = parent::resolveRecord($key);
        abort_unless($survey instanceof Survey, 404);
        SchoolAccess::authorize($survey);

        return $survey;
    }

    protected function getViewData(): array
    {
        $survey = $this->getRecord();
        abort_unless($survey instanceof Survey, 404);
        SchoolAccess::authorize($survey);

        $report = app(SurveyReportService::class)->build($survey);
        $report['minimum_responses'] = max(5, (int) $report['minimum_responses']);
        $report['suppressed'] = $report['suppressed']
            || $report['response_count'] < $report['minimum_responses'];

        if ($report['suppressed']) {
            $report['questions'] = [];
        }

        return ['survey' => $survey, 'report' => $report];
    }
}
