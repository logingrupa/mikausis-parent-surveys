<?php

namespace App\Filament\Resources\Surveys;

use App\Filament\Forms\SurveyEditor;
use App\Filament\Resources\Surveys\RelationManagers\InvitationsRelationManager;
use App\Filament\Support\SchoolAccess;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class SurveyResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static ?string $slug = 'aptaujas';

    protected static ?string $modelLabel = 'aptauja';

    protected static ?string $pluralModelLabel = 'aptaujas';

    protected static ?string $navigationLabel = 'Aptaujas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        $schoolId = SchoolAccess::schoolId();

        return parent::getEloquentQuery()->when($schoolId,
            fn (Builder $query) => $query->where('school_id', $schoolId),
            fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $allowed = SchoolAccess::schoolId() !== null
            && in_array($action, ['viewAny', 'view', 'create', 'update', 'replicate'], true)
            && ($record === null || ($record instanceof Survey && SchoolAccess::owns($record)));

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SurveyEditor::make('builder')
                ->hiddenLabel()
                ->default(fn (): array => SurveyEditorData::empty())
                ->columnSpanFull(),
            Section::make('Papildu iestatījumi')
                ->description('Ja nepieciešams, norādiet aptaujas beigu datumu.')
                ->collapsed()
                ->schema([
                    DateTimePicker::make('closes_at')->label('Pieņemt atbildes līdz')->seconds(false)->native(false)->helperText('Atstājiet tukšu, lai aptauja būtu bez termiņa. Laiks: Eiropa/Rīga.'),
                    TextInput::make('estimated_minutes')->label('Aptuvenais aizpildīšanas laiks (minūtes)')->numeric()->minValue(1)->maxValue(120)->default(7)->required(),
                ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Stack::make([
                TextColumn::make('title')->label('Aptauja')->searchable()->sortable()->wrap()->weight('semibold')->description(fn (Survey $record): string => count($record->questions ?? []).' jautājumi'),
                Split::make([
                    TextColumn::make('status')->label('Statuss')->badge()->formatStateUsing(fn (string $state): string => self::statusLabel($state))->color(fn (string $state): string => match ($state) {
                        'open' => 'success', 'closed' => 'gray', default => 'warning'
                    }),
                    TextColumn::make('responses_count')->label('Atbildes')->prefix('Atbildes: ')->counts('responses')->sortable(),
                    TextColumn::make('invitations_count')->label('Ģimenes saites')->prefix('Ģimenes saites: ')->counts('invitations'),
                    TextColumn::make('closes_at')->label('Termiņš')->dateTime('d.m.Y. H:i')->placeholder('Bez termiņa')->sortable(),
                ])->extraAttributes(['class' => 'survey-card-details']),
            ])->space(3),
        ])->defaultSort('created_at', 'desc')
            ->contentGrid(['default' => 1])
            ->extraAttributes(['class' => 'survey-directory-table'])
            ->filters([SelectFilter::make('status')->label('Statuss')->options(['draft' => 'Melnraksts', 'open' => 'Atvērta', 'closed' => 'Slēgta'])])
            ->recordActions([
                EditAction::make()->label('Pārvaldīt')->button(),
                ...self::workflowActions(),
            ])
            ->emptyStateHeading('Vēl nav aptauju')->emptyStateDescription('Izveidojiet pirmo aptauju, pievienojiet jautājumus un pēc satura pārbaudes publicējiet saiti vecākiem.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function workflowActions(): array
    {
        $actions = [
            Action::make('publish')->label(fn (Survey $record): string => $record->published_at ? 'Publicēt vēlreiz' : 'Publicēt')
                ->icon('heroicon-o-paper-airplane')->visible(fn (Survey $record): bool => $record->status !== 'open' && SchoolAccess::owns($record))
                ->requiresConfirmation()->modalHeading('Atvērt aptauju vecākiem?')->modalDescription('Tiks saglabātas lapā veiktās izmaiņas un atvērta saite vecākiem. Aptauju varēsiet labot arī pēc publicēšanas; iepriekšējās atbildes saglabāsies.')
                ->action(function (Survey $record, $livewire) {
                    try {
                        if ($livewire instanceof Pages\EditSurvey) {
                            $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                            $livewire->publishSurvey();
                        } else {
                            SurveyWorkflow::publish($record);
                        }
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Aptauju vēl nevar publicēt')->body(e(implode(' ', array_unique($exception->validator->errors()->all()))))->danger()->persistent()->send();
                        throw $exception;
                    }
                    Notification::make()->title('Aptauja ir atvērta')->success()->send();

                    return redirect(self::getUrl('edit', ['record' => $record]));
                }),
            Action::make('close')->label('Slēgt aptauju')->icon('heroicon-o-lock-closed')->color('gray')->outlined()->visible(fn (Survey $record): bool => $record->status === 'open' && SchoolAccess::owns($record))->requiresConfirmation()
                ->action(function (Survey $record) {
                    SurveyWorkflow::close($record);
                    Notification::make()->title('Aptauja slēgta')->success()->send();

                    return redirect(self::getUrl('edit', ['record' => $record]));
                }),
            Action::make('unpublish')->label('Atcelt publicēšanu')->icon('heroicon-o-eye-slash')->color('danger')->outlined()
                ->visible(fn (Survey $record): bool => $record->published_at !== null && $record->unpublished_at === null && SchoolAccess::owns($record))
                ->requiresConfirmation()->modalHeading('Atcelt publicēšanu')->modalDescription('Aptauja vairs nebūs pieejama ar publisko saiti. Atbildes un kontakti tiks saglabāti.')
                ->action(function (Survey $record) {
                    SurveyWorkflow::unpublish($record);

                    return redirect(self::getUrl('edit', ['record' => $record]));
                }),
            Action::make('duplicate')->label('Kopēt jaunai aptaujai')->icon('heroicon-o-document-duplicate')->color('gray')->outlined()->visible(fn (Survey $record): bool => SchoolAccess::owns($record))
                ->action(function (Survey $record) {
                    $copy = SurveyWorkflow::duplicate($record);

                    return redirect(self::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('public')->label('Atvērt aptauju')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')->outlined()->button()->visible(fn (Survey $record): bool => (bool) $record->published_at && $record->unpublished_at === null && SchoolAccess::owns($record))->url(fn (Survey $record): string => route('surveys.show', ['slug' => $record->slug]))->openUrlInNewTab(),
            Action::make('share')->label('Dalīties')->icon('heroicon-o-share')->color('gray')->outlined()->button()
                ->visible(fn (Survey $record): bool => (bool) $record->published_at && $record->unpublished_at === null && SchoolAccess::owns($record))
                ->alpineClickHandler('window.MikausisSurveyShare.share($el)')
                ->extraAttributes(fn (Survey $record): array => ['data-survey-share' => '', 'data-share-url' => route('surveys.show', ['slug' => $record->slug]), 'data-share-title' => $record->title, 'data-share-copy' => 'Kopēt saiti', 'data-share-success' => 'Saite nokopēta']),
            Action::make('report')->label('Rezultātu pārskats')->icon('heroicon-o-chart-bar')->color('gray')->outlined()->visible(fn (Survey $record): bool => SchoolAccess::owns($record))->url(fn (Survey $record): string => self::getUrl('report', ['record' => $record])),
        ];

        $actions = collect($actions)->keyBy(fn (Action $action): string => $action->getName());

        return [
            $actions['publish'],
            $actions['public'],
            $actions['share'],
            ActionGroup::make([$actions['report'], $actions['close'], $actions['unpublish'], $actions['duplicate']])
                ->label('Vairāk')->button()->outlined()->color('gray')->icon('heroicon-o-ellipsis-horizontal'),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Atvērta', 'closed' => 'Slēgta', default => 'Melnraksts'
        };
    }

    public static function getRelations(): array
    {
        return [InvitationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveys::route('/'),
            'create' => Pages\CreateSurvey::route('/izveidot'),
            'edit' => Pages\EditSurvey::route('/{record}/labot'),
            'report' => Pages\SurveyReport::route('/{record}/atskaite'),
            'contacts' => Pages\QuestionContacts::route('/{record}/kontakti'),
        ];
    }
}
