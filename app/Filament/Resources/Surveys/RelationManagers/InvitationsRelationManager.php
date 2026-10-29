<?php

namespace App\Filament\Resources\Surveys\RelationManagers;

use App\Filament\Support\SchoolAccess;
use App\Models\FamilyInvitation;
use App\Models\Survey;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Ģimeņu uzaicinājumi';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Survey && SchoolAccess::owns($ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('Ģimenes apzīmējums')->placeholder('Ģimene 01')->required()->maxLength(120)
                ->helperText('Izmantojiet neitrālu apzīmējumu. Bērna vārds un citi personas dati nav nepieciešami.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->heading('Ģimeņu uzaicinājumi')
            ->description('Kopējiet saiti un nosūtiet ģimenei. Lomas parāda tikai dalību; atbilžu saturs nav piesaistīts ģimenei.')
            ->recordTitleAttribute('label')
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query->where('school_id', SchoolAccess::schoolId())->with('participations');
            })
            ->columns([
                TextColumn::make('label')->label('Ģimene')->searchable()->wrap(),
                TextColumn::make('family_link')->label('Ģimenes saite')
                    ->getStateUsing(fn (FamilyInvitation $record): string => route('surveys.family', ['slug' => $this->getOwnerRecord()->slug, 'token' => $record->token]))
                    ->copyable()->copyMessage('Saite nokopēta')->wrap(),
                ...collect(['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'])->map(fn (string $label, string $role) => TextColumn::make('role_'.$role)->label($label)->badge()
                    ->getStateUsing(fn (FamilyInvitation $record): string => $record->participations->contains('role', $role) ? 'Piedalījās' : 'Vēl nav')
                    ->color(fn (string $state): string => $state === 'Piedalījās' ? 'success' : 'gray')
                )->values()->all(),
            ])
            ->headerActions([
                CreateAction::make()->label('Izveidot ģimenes saiti')->modalHeading('Jauns ģimenes uzaicinājums')->modalSubmitActionLabel('Izveidot saiti')->createAnother(false)
                    ->authorize(fn (): bool => SchoolAccess::owns($this->getOwnerRecord()))
                    ->using(function (array $data): FamilyInvitation {
                        $survey = Survey::query()->findOrFail($this->getOwnerRecord()->getKey());
                        SchoolAccess::authorize($survey);
                        $invitation = new FamilyInvitation;
                        $invitation->forceFill(['label' => $data['label'], 'survey_id' => $survey->id, 'school_id' => $survey->school_id])->save();

                        return $invitation;
                    }),
            ])
            ->emptyStateHeading('Ģimenes saites vēl nav izveidotas')
            ->emptyStateDescription('Var izmantot kopīgo aptaujas saiti vai izveidot atsevišķus uzaicinājumus ģimeņu dalības uzskaitei.');
    }
}
