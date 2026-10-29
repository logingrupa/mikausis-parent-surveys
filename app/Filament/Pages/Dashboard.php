<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Pārskats';

    protected static ?string $navigationLabel = 'Pārskats';

    protected string $view = 'filament.pages.dashboard';

    public function getWidgets(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        $surveys = SurveyResource::getEloquentQuery()->withCount(['responses', 'invitations'])->latest()->get();

        return [
            'surveys' => $surveys->take(8),
            'surveyCount' => $surveys->count(),
            'openCount' => $surveys->filter(fn ($survey): bool => $survey->isAcceptingResponses())->count(),
            'responseCount' => $surveys->sum('responses_count'),
            'invitationCount' => $surveys->sum('invitations_count'),
        ];
    }
}
