<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\HandlesBuilderErrors;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSurvey extends CreateRecord
{
    use HandlesBuilderErrors;

    protected static string $resource = SurveyResource::class;

    protected static ?string $title = 'Jauna aptauja';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $data = SurveyEditorData::prepareForSave($data);
        if (isset($data['builder'])) {
            $this->data['builder'] = $data['builder'];
        }

        return $this->withBuilderValidation(fn () => SurveyWorkflow::create($data));
    }

    protected function getRedirectUrl(): string
    {
        return SurveyResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
