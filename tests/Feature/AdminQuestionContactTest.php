<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\Pages\QuestionContacts;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Models\QuestionContact;
use App\Models\School;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminQuestionContactTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOM_KEY = 'detail_3826bc18-d8c2-48a6-9577-105e8f234824'; // gitleaks:allow -- synthetic field identifier

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
        $app['db']->purge('sqlite');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_page_requires_an_admin_with_an_existing_school(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->contact($survey);
        $url = $this->contactsUrl($survey);

        $this->get($url)->assertRedirect(url('/admin/login'));
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->user($school, false))->get($url)->assertForbidden();
        $this->actingAs($this->user(null))->get($url)->assertForbidden();

        $staleAdmin = $this->user($school);
        $staleAdmin->school_id = 999999;
        $this->actingAs($staleAdmin)->get($url)->assertForbidden();
    }

    public function test_foreign_and_missing_survey_parameters_cannot_expose_contacts(): void
    {
        $school = $this->school();
        $own = $this->survey($school);
        $foreign = $this->survey($this->school());
        $this->contact($foreign, name: 'FOREIGN-CONTACT');
        $this->actingAs($this->user($school));

        $this->get($this->contactsUrl($own))->assertOk()->assertDontSee('FOREIGN-CONTACT');
        $this->get($this->contactsUrl($foreign))->assertNotFound()->assertDontSee('FOREIGN-CONTACT');
        $this->get('/admin/aptaujas/999999/kontakti')->assertNotFound();
        $this->assertThrows(fn () => Livewire::test(QuestionContacts::class, ['record' => $foreign->id]), ModelNotFoundException::class);
    }

    #[DataProvider('smallResponseCounts')]
    public function test_voluntary_contacts_are_available_below_the_anonymous_reporting_threshold(int $count): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->contact($survey);
        for ($index = 0; $index < $count; $index++) {
            SurveyResponse::create(['survey_id' => $survey->id, 'answers' => []]);
        }
        $this->actingAs($this->user($school));

        $this->get($this->contactsUrl($survey))->assertOk()
            ->assertSee('Brīvprātīgie kontakti')
            ->assertSee('Atsevišķi, brīvprātīgi atstāta kontaktinformācija')
            ->assertSee('mazāk nekā piecas anketas')
            ->assertSee('Pavasara talka')
            ->assertSee('Palīdzēšu dārzā')
            ->assertSee('Anna Liepa')
            ->assertSee('anna@example.test');

        $this->get(SurveyResource::getUrl('report', ['record' => $survey]))->assertOk()
            ->assertSee($this->contactsUrl($survey), false)
            ->assertSee('Brīvprātīgie kontakti')
            ->assertDontSee('Anna Liepa')
            ->assertDontSee('anna@example.test');
    }

    public static function smallResponseCounts(): array
    {
        return [[0], [1], [4]];
    }

    public function test_only_configured_contact_fields_and_that_questions_selected_answer_are_rendered(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $mainResponse = SurveyResponse::create([
            'survey_id' => $survey->id,
            'answers' => ['private_note' => ['value' => 'UNRELATED-ANSWER-SECRET']],
            'respondent_details' => ['fields' => [['key' => 'name', 'label' => 'PRIVATE-MAIN-LABEL', 'type' => 'text', 'value' => 'MAIN-IDENTITY-SECRET']]],
        ]);
        $contact = $this->contact($survey);
        $contact->update(['answer' => ['values' => ['garden', 'books'], 'labels' => ['UNTRUSTED-ANSWER-LABEL']], 'details' => [
            'fields' => [
                ['key' => 'name', 'label' => 'UNTRUSTED-FIELD-LABEL', 'type' => 'text', 'value' => 'Anna Liepa'],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'value' => 'anna@example.test'],
                ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'value' => 'guardian'],
                ['key' => self::CUSTOM_KEY, 'label' => 'Saziņas laiks', 'type' => 'text', 'value' => 'father'],
                ['key' => 'unknown', 'label' => 'UNCONFIGURED-FIELD', 'type' => 'text', 'value' => 'UNCONFIGURED-VALUE'],
            ],
            'submission_id' => $mainResponse->id,
            'family_id' => 'PRIVATE-FAMILY-ID',
            'session_id' => 'PRIVATE-SESSION-ID',
            'submitted_at' => 'PRIVATE-TIMESTAMP',
        ]]);
        $this->actingAs($this->user($school));

        $response = $this->get($this->contactsUrl($survey))->assertOk()
            ->assertSee('Palīdzēšu dārzā; Sakārtošu grāmatas')
            ->assertSee('Vārds un uzvārds')->assertSee('Anna Liepa')
            ->assertSee('Cits aizbildnis')->assertSee('Saziņas laiks')->assertSee('father');

        foreach ([$mainResponse->id, self::CUSTOM_KEY, 'Privāts jautājums', 'UNRELATED-ANSWER-SECRET', 'PRIVATE-MAIN-LABEL',
            'MAIN-IDENTITY-SECRET', 'PRIVATE-FAMILY-ID', 'PRIVATE-SESSION-ID', 'PRIVATE-TIMESTAMP',
            'UNTRUSTED-ANSWER-LABEL', 'UNTRUSTED-FIELD-LABEL', 'UNCONFIGURED-FIELD', 'UNCONFIGURED-VALUE'] as $secret) {
            $response->assertDontSee($secret, false);
        }

        Livewire::test(QuestionContacts::class, ['record' => $survey->id])
            ->assertViewHas('groups', function (array $groups): bool {
                $entry = $groups[0]['entries'][0];

                return array_keys($entry) === ['id', 'answers', 'fields']
                    && array_keys($entry['fields'][0]) === ['label', 'value'];
            });
    }

    public function test_contacts_remain_in_separate_question_groups_without_visible_ids(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->contact($survey, name: 'TALKAS-KONTAKTS');
        $this->contact($survey, question: 'concert', name: 'KONCERTA-KONTAKTS');
        $this->actingAs($this->user($school));

        Livewire::test(QuestionContacts::class, ['record' => $survey->id])
            ->assertViewHas('groups', function (array $groups): bool {
                return array_column($groups, 'title') === ['Pavasara talka', 'Pavasara koncerts']
                    && $groups[0]['entries'][0]['fields'][0]['value'] === 'TALKAS-KONTAKTS'
                    && $groups[1]['entries'][0]['fields'][0]['value'] === 'KONCERTA-KONTAKTS';
            });

        $html = $this->get($this->contactsUrl($survey))->assertOk()->getContent();
        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', $visible);
        $this->assertStringNotContainsString('created_at', $visible);
        $this->assertStringNotContainsString('submitted_at', $visible);
    }

    public function test_contact_content_and_question_configuration_are_html_escaped(): void
    {
        $school = $this->school();
        $questions = $this->questions();
        $questions[0]['title'] = 'Talka <script>alert("title")</script>';
        $questions[0]['options'][0]['label'] = '<img src=x onerror="alert(1)">';
        $questions[0]['contact']['purpose'] = '<svg onload="alert(2)">';
        $questions[0]['contact']['fields'][0]['label'] = '<b>Vārds</b>';
        $survey = $this->survey($school, ['questions' => $questions]);
        $name = '<script>alert("name")</script>';
        $this->contact($survey, name: $name);
        $this->actingAs($this->user($school));

        $response = $this->get($this->contactsUrl($survey))->assertOk();
        foreach ([$questions[0]['title'], $questions[0]['options'][0]['label'], $questions[0]['contact']['purpose'], '<b>Vārds</b>', $name] as $text) {
            $response->assertSee(e($text), false)->assertDontSee($text, false);
        }
    }

    public function test_deletion_requires_confirmation_and_only_deletes_the_selected_contact(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $first = $this->contact($survey);
        $second = $this->contact($survey, name: 'Saglabāt šo kontaktu');
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'answers' => ['private_note' => ['value' => 'ANONYMOUS-ANSWER']]]);
        $this->actingAs($this->user($school));
        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id]);

        $component->assertActionExists('deleteContact', fn (Action $action): bool => $action->isConfirmationRequired())
            ->mountAction('deleteContact', ['contact' => $first->id]);
        $this->assertTrue($first->fresh() !== null);
        $component->unmountAction();
        $this->assertTrue($first->fresh() !== null);

        $component->mountAction('deleteContact', ['contact' => $first->id])->callMountedAction()->assertHasNoActionErrors();

        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        $this->assertModelExists($survey);
        $this->assertSame(['private_note' => ['value' => 'ANONYMOUS-ANSWER']], $response->fresh()->answers);
    }

    #[DataProvider('forgedContactArguments')]
    public function test_forged_delete_arguments_cannot_cross_the_current_survey(string $scope, string $attack): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $own = $this->contact($survey);
        $otherSurvey = $this->survey($scope === 'same_school' ? $school : $this->school());
        $other = $this->contact($otherSurvey, name: 'Foreign contact');
        $this->actingAs($this->user($school));
        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id]);

        if ($attack === 'mount') {
            $component->call('mountAction', 'deleteContact', ['contact' => $other->id]);
            $this->assertThrows(fn () => $component->call('callMountedAction'), ModelNotFoundException::class);
        } else {
            $component->call('mountAction', 'deleteContact', ['contact' => $own->id]);
            if ($attack === 'mutable_state') {
                $component->set('mountedActions.0.arguments.contact', $other->id);
                $this->assertThrows(fn () => $component->call('callMountedAction'), ModelNotFoundException::class);
            } else {
                $this->assertThrows(fn () => $component->call('callMountedAction', ['contact' => $other->id, 'survey_id' => $otherSurvey->id]), ModelNotFoundException::class);
            }
        }

        $this->assertModelExists($own);
        $this->assertModelExists($other);
    }

    public static function forgedContactArguments(): array
    {
        return [
            ['same_school', 'mount'], ['same_school', 'mutable_state'], ['same_school', 'confirmation'],
            ['other_school', 'mount'], ['other_school', 'mutable_state'], ['other_school', 'confirmation'],
        ];
    }

    #[DataProvider('invalidContactIds')]
    public function test_invalid_delete_arguments_cannot_delete_contacts(mixed $id): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $contact = $this->contact($survey);
        $this->actingAs($this->user($school));

        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id])
            ->call('mountAction', 'deleteContact', ['contact' => $contact->id]);

        if (is_string($id) && Str::isUuid($id)) {
            $this->assertThrows(fn () => $component->call('callMountedAction', ['contact' => $id]), ModelNotFoundException::class);
        } else {
            $component->call('callMountedAction', ['contact' => $id])->assertNotFound();
        }

        $this->assertModelExists($contact);
    }

    public static function invalidContactIds(): array
    {
        return [[null], [''], ['invalid'], [123], [false], [[]], [['first', 'second']], ['817d0bc4-a68a-401c-a8a7-373e8c6dc060']];
    }

    #[DataProvider('revocations')]
    public function test_authorization_is_rechecked_when_confirming_deletion_or_refreshing(string $revocation): void
    {
        $school = $this->school();
        $survey = $this->survey($school, ['status' => 'draft']);
        $contact = $this->contact($survey);
        $admin = $this->user($school);
        $this->actingAs($admin);
        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id])
            ->call('mountAction', 'deleteContact', ['contact' => $contact->id]);

        if ($revocation === 'admin') {
            $admin->forceFill(['is_admin' => false])->save();
        } elseif ($revocation === 'user_school') {
            $admin->forceFill(['school_id' => $this->school()->id])->save();
        } else {
            $survey->update(['school_id' => $this->school()->id]);
        }

        $component->call('callMountedAction')->assertStatus($revocation === 'admin' ? 403 : 404);
        $this->assertModelExists($contact);
    }

    public static function revocations(): array
    {
        return [['admin'], ['user_school'], ['survey_school']];
    }

    public function test_livewire_refresh_denies_a_revoked_administrator(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $admin = $this->user($school);
        $this->contact($survey);
        $this->actingAs($admin);
        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id]);

        $admin->forceFill(['is_admin' => false])->save();

        $component->call('$refresh')->assertForbidden()->assertDontSee('Anna Liepa');
    }

    public function test_livewire_record_parameter_is_locked(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $other = $this->survey($this->school());
        $this->actingAs($this->user($school));
        $component = Livewire::test(QuestionContacts::class, ['record' => $survey->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('record', $other->id);
    }

    public function test_surveys_without_question_contacts_have_an_empty_list_and_no_report_link(): void
    {
        $school = $this->school();
        $questions = $this->questions();
        unset($questions[0]['contact'], $questions[1]['contact']);
        $survey = $this->survey($school, ['questions' => $questions]);
        $this->actingAs($this->user($school));

        $this->get($this->contactsUrl($survey))->assertOk()->assertSee('Šajā aptaujā nav jautājumu ar kontaktinformācijas bloku');
        $this->get(SurveyResource::getUrl('report', ['record' => $survey]))->assertOk()->assertDontSee('Brīvprātīgie kontakti');
    }

    private function school(): School
    {
        return School::create(['name' => 'Testa iestāde', 'slug' => 'school-'.Str::uuid()]);
    }

    private function user(?School $school, bool $isAdmin = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['school_id' => $school?->id, 'is_admin' => $isAdmin])->save();

        return $user;
    }

    private function survey(School $school, array $attributes = []): Survey
    {
        return Survey::create(array_replace([
            'school_id' => $school->id,
            'title' => 'Aptauja ar brīvprātīgiem kontaktiem',
            'status' => 'open',
            'sections' => [['key' => 'general', 'title' => 'Vispārīgi']],
            'questions' => $this->questions(),
        ], $attributes));
    }

    private function questions(): array
    {
        $contact = [
            'prompt' => 'Atstāt kontaktinformāciju',
            'purpose' => 'Kontakti tikai šīs aktivitātes organizēšanai.',
            'trigger_values' => ['garden'],
            'fields' => [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
                ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'required' => false],
                ['key' => self::CUSTOM_KEY, 'label' => 'Saziņas laiks', 'type' => 'text', 'required' => false],
            ],
        ];

        return [
            ['key' => 'talka', 'section' => 'general', 'title' => 'Pavasara talka', 'type' => 'multiple', 'required' => false,
                'allow_other' => false, 'exclusive_values' => [], 'options' => [
                    ['value' => 'garden', 'label' => 'Palīdzēšu dārzā'], ['value' => 'books', 'label' => 'Sakārtošu grāmatas'],
                ], 'contact' => $contact],
            ['key' => 'concert', 'section' => 'general', 'title' => 'Pavasara koncerts', 'type' => 'single', 'required' => false,
                'allow_other' => false, 'exclusive_values' => [], 'options' => [['value' => 'garden', 'label' => 'Palīdzēšu koncertā']], 'contact' => $contact],
            ['key' => 'private_note', 'section' => 'general', 'title' => 'Privāts jautājums', 'type' => 'text', 'required' => false,
                'allow_other' => false, 'exclusive_values' => [], 'options' => []],
        ];
    }

    private function contact(Survey $survey, string $question = 'talka', string $name = 'Anna Liepa'): QuestionContact
    {
        return QuestionContact::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_key' => $question,
            'answer' => ['values' => ['garden'], 'labels' => ['Palīdzēšu dārzā']],
            'details' => ['fields' => [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'value' => $name],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'value' => 'anna@example.test'],
            ]],
        ]);
    }

    private function contactsUrl(Survey $survey): string
    {
        return SurveyResource::getUrl('contacts', ['record' => $survey]);
    }
}
