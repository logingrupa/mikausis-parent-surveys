<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\SchoolAccess;
use App\Models\QuestionContact;
use App\Models\Survey;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QuestionContacts extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SurveyResource::class;

    protected static ?string $breadcrumb = 'Brīvprātīgie kontakti';

    protected string $view = 'filament.resources.surveys.pages.question-contacts';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Brīvprātīgie kontakti';
    }

    protected function resolveRecord(int|string $key): Model
    {
        abort_unless(SchoolAccess::schoolId() !== null, 403);

        $survey = parent::resolveRecord($key);
        abort_unless($survey instanceof Survey, 404);
        SchoolAccess::authorize($survey);

        return $survey;
    }

    protected function authorizedSurvey(): Survey
    {
        abort_unless(SchoolAccess::schoolId() !== null, 403);

        // Requery on every render and action: ownership may change after mounting.
        $survey = SurveyResource::getEloquentQuery()->findOrFail($this->getRecord()->getKey());
        SchoolAccess::authorize($survey);

        return $survey;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')->label('Aptaujas rezultāti')->color('gray')
                ->icon('heroicon-o-chart-bar')
                ->url(fn (): string => SurveyResource::getUrl('report', ['record' => $this->getRecord()])),
        ];
    }

    public function deleteContactAction(): Action
    {
        return Action::make('deleteContact')
            ->label('Dzēst kontaktu')->icon('heroicon-o-trash')->color('danger')->outlined()
            ->requiresConfirmation()
            ->modalHeading('Dzēst šo kontaktinformāciju?')
            ->modalDescription('Kontaktinformācija tiks neatgriezeniski dzēsta. Aptaujas anonīmās atbildes netiks mainītas.')
            ->modalSubmitActionLabel('Dzēst kontaktu')
            ->modalCancelActionLabel('Atcelt')
            ->action(function (array $arguments): void {
                $survey = $this->authorizedSurvey();
                $id = $arguments['contact'] ?? null;
                abort_unless(is_string($id) && Str::isUuid($id), 404);

                QuestionContact::query()->where('survey_id', $survey->getKey())->whereKey($id)
                    ->firstOrFail()->delete();

                Notification::make()->title('Kontaktinformācija dzēsta')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        $survey = $this->authorizedSurvey();
        // Random order prevents matching rows across question contact lists by position.
        $contacts = QuestionContact::query()->where('survey_id', $survey->getKey())
            ->inRandomOrder()->get(['id', 'question_key', 'answer', 'details'])->groupBy('question_key');
        $groups = [];

        foreach ($survey->reportQuestions() as $question) {
            if (! is_array($question['contact'] ?? null)) {
                continue;
            }
            if (($question['archived'] ?? false) && $contacts->get($question['key'], collect())->isEmpty()) {
                continue;
            }

            $groups[] = [
                'title' => $question['title'],
                'purpose' => $question['contact']['purpose'],
                'archived' => (bool) ($question['archived'] ?? false),
                'entries' => $contacts->get($question['key'], collect())
                    ->map(fn (QuestionContact $contact): array => $this->displayContact($contact, $question))->all(),
            ];
        }

        return ['surveyTitle' => $survey->title, 'groups' => $groups];
    }

    private function displayContact(QuestionContact $contact, array $question): array
    {
        $answer = $contact->answer;
        $values = is_array($answer['values'] ?? null) ? $answer['values'] : [];
        $labels = [];

        foreach ($question['options'] ?? [] as $option) {
            if (in_array($option['value'], $values, true)) {
                $labels[] = $option['label'];
            }
        }

        $details = $contact->details;
        $storedFields = collect(is_array($details['fields'] ?? null) ? $details['fields'] : [])
            ->filter(fn ($field): bool => is_array($field) && is_string($field['key'] ?? null))
            ->keyBy('key');
        $fields = [];

        foreach ($question['contact']['fields'] as $field) {
            $stored = $storedFields->get($field['key']);
            $value = $stored['value'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if ($field['type'] === 'role') {
                $value = match ($value) {
                    'mother' => 'Mamma',
                    'father' => 'Tētis',
                    'guardian' => 'Cits aizbildnis',
                    default => null,
                };
            }

            if ($value !== null) {
                $fields[] = ['label' => $field['label'], 'value' => $value];
            }
        }

        return ['id' => $contact->getKey(), 'answers' => $labels, 'fields' => $fields];
    }
}
