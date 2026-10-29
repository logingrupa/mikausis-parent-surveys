<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\HandlesBuilderErrors;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSurvey extends EditRecord
{
    use HandlesBuilderErrors;

    protected static string $resource = SurveyResource::class;

    protected static ?string $title = 'Pārvaldīt aptauju';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SurveyEditorData::fromDefinition($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = SurveyEditorData::prepareForSave($data);
        if (isset($data['builder'])) {
            $this->data['builder'] = $data['builder'];
        }
        $this->withBuilderValidation(fn () => SurveyWorkflow::update($record, $data));
        $record->refresh();
        $this->data['builder'] = SurveyEditorData::fromDefinition($record->toArray())['builder'];
        $this->dispatch('survey-editor-saved');

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return SurveyResource::workflowActions();
    }

    public function publishSurvey(): Survey
    {
        return $this->withBuilderValidation(fn () => SurveyWorkflow::publish($this->getRecord()));
    }
}
