<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\Pages\SurveyReport;
use App\Models\FamilyInvitation;
use App\Models\FamilyParticipation;
use App\Models\School;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_TEXT = 'PRIVATE-TEXT-<script>alert("respondent")</script>';

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

    public function test_guests_are_redirected_to_admin_login_for_both_endpoints(): void
    {
        $survey = $this->survey($this->school());

        $this->get($this->reportUrl($survey))->assertRedirect(url('/admin/login'));
        $this->get(route('reports.export', $survey))->assertRedirect(url('/admin/login'));
        $this->getJson(route('reports.export', $survey))->assertUnauthorized();
    }

    public function test_non_admins_cannot_read_or_export_reports(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, 5);
        $this->actingAs($this->user($school, false));

        $this->get($this->reportUrl($survey))->assertForbidden();
        $this->get(route('reports.export', $survey))->assertForbidden();
    }

    public function test_admins_without_a_school_cannot_read_or_export_reports(): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey, 5);
        $this->actingAs($this->user(null));

        $this->get($this->reportUrl($survey))->assertForbidden();
        $this->get(route('reports.export', $survey))->assertForbidden();
    }

    public function test_an_existing_school_is_required_even_when_a_school_id_is_set(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $admin = $this->user($school);
        // A stale authenticated identity must not be treated as an existing school.
        $admin->school_id = 999999;
        $this->actingAs($admin);

        $this->get($this->reportUrl($survey))->assertForbidden();
        $this->get(route('reports.export', $survey))->assertForbidden();
    }

    public function test_admins_cannot_read_or_export_another_schools_survey(): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey, 5);
        $this->actingAs($this->user($this->school()));

        $response = $this->get($this->reportUrl($survey));
        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee(self::PRIVATE_TEXT);
        $this->get(route('reports.export', $survey))->assertForbidden();
    }

    public function test_missing_surveys_return_not_found(): void
    {
        $this->actingAs($this->user($this->school()));

        $this->get('/admin/aptaujas/999999/atskaite')->assertNotFound();
        $this->get('/admin/aptaujas/999999/eksports')->assertNotFound();
    }

    #[DataProvider('suppressedCounts')]
    public function test_small_reports_hide_question_content_and_refuse_export(int $count): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, $count);
        $this->actingAs($this->user($school));

        $response = $this->get($this->reportUrl($survey))
            ->assertOk()
            ->assertSee('vismaz 5 anketas')
            ->assertSee('CSV vēl nav pieejams')
            ->assertDontSee('Vai vēlaties jaunumus?')
            ->assertDontSee('Jā, noteikti')
            ->assertDontSee(self::PRIVATE_TEXT)
            ->assertDontSee('PRIVATE-TEXT-', false)
            ->assertDontSee('window.print()', false)
            ->assertDontSee(route('reports.html', $survey), false)
            ->assertDontSee(route('reports.html.download', $survey), false);

        $button = $this->reportAction($response->getContent(), 'Drukāt atskaiti');
        $this->assertSame('button', $button->tagName);
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertFalse($button->hasAttribute('href'));
        $this->assertFalse($button->hasAttribute('x-on:click'));

        $response->assertSee($count === 0
            ? 'Vēl nav iesniegtu atbilžu'
            : 'Rezultātu apkopošanai vajadzīgas vēl atbildes');
        $this->get(route('reports.export', $survey))->assertForbidden();
    }

    public static function suppressedCounts(): array
    {
        return ['empty' => [0], 'below minimum' => [4]];
    }

    public function test_five_responses_show_real_aggregates_escaped_comments_and_separate_participation_totals(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, 5);
        $invitation = $this->invitation($survey);
        FamilyParticipation::create(['invitation_id' => $invitation->id, 'role' => 'mother']);
        FamilyParticipation::create(['invitation_id' => $invitation->id, 'role' => 'father']);

        $foreignSurvey = $this->survey($this->school());
        $this->responses($foreignSurvey, 5);
        SurveyResponse::create(['survey_id' => $foreignSurvey->id, 'answers' => ['note' => ['value' => 'FOREIGN-SCHOOL-COMMENT']]]);
        $this->actingAs($this->user($school));

        $this->get($this->reportUrl($survey))
            ->assertOk()
            ->assertSee('Vai vēlaties jaunumus?')
            ->assertSee('3 no 4 · 75,00%')
            ->assertSee('2 no 3 · 66,67%')
            ->assertSee('0 no 0 · 0,00%')
            ->assertSee('Procentu saucējs: 4 atbildējušie.')
            ->assertSee('procentu summa var pārsniegt 100%')
            ->assertSee('nosacītais papildjautājums netika parādīts')
            ->assertSeeInOrder(['Ģimeņu uzaicinājumi', '<dd>1</dd>', 'Atzīmētas dalības', '<dd>2</dd>'], false)
            ->assertSee(self::PRIVATE_TEXT)
            ->assertDontSee(self::PRIVATE_TEXT, false)
            ->assertDontSee('FOREIGN-SCHOOL-COMMENT')
            ->assertDontSee($invitation->token)
            ->assertDontSee($invitation->label)
            ->assertSee(route('reports.export', $survey), false)
            ->assertDontSee('window.print()', false);
    }

    public function test_print_action_opens_the_standalone_report_with_an_explicit_print_flag(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, 5);
        $this->actingAs($this->user($school));

        $response = $this->get($this->reportUrl($survey))->assertOk()
            ->assertDontSee('window.print()', false);
        $html = $response->getContent();
        foreach ([
            'Drukāt atskaiti' => route('reports.html', ['survey' => $survey, 'print' => 1]),
            'Atvērt drukas skatu' => route('reports.html', $survey),
            'Lejupielādēt HTML' => route('reports.html.download', $survey),
        ] as $label => $url) {
            $link = $this->reportAction($html, $label);
            $this->assertSame('a', $link->tagName);
            $this->assertSame($url, $link->getAttribute('href'));
            $this->assertFalse($link->hasAttribute('x-on:click'));
            if ($label !== 'Lejupielādēt HTML') {
                $this->assertSame('_blank', $link->getAttribute('target'));
                $this->assertContains('noopener', explode(' ', $link->getAttribute('rel')));
                $this->assertContains('noreferrer', explode(' ', $link->getAttribute('rel')));
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\.fi-header\b[^{}]*\{[^}]*display\s*:\s*none/i', $html);
    }

    public function test_csv_stream_contains_only_allowed_aggregates_with_question_denominators(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $responseIds = $this->responses($survey, 5);
        $invitation = $this->invitation($survey);
        $admin = $this->user($school);
        $this->actingAs($admin);

        $response = $this->get(route('reports.export', $survey))
            ->assertOk()
            ->assertStreamed()
            ->assertDownload('aptaujas-atskaite-'.$survey->id.'.csv')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $rows = $this->csvRows($csv);
        $header = array_search('Jautājuma kods', array_column($rows, 0), true);
        $this->assertNotFalse($header);
        $aggregates = array_slice($rows, $header + 1);
        $this->assertCount(8, $aggregates);
        $byOption = array_column($aggregates, null, 5);
        $this->assertSame(['choice', 'Vai vēlaties jaunumus?', 'single', '4', '1', 'yes', 'Jā, noteikti', '3', '75.00'], $byOption['yes']);
        $this->assertSame(['multi', 'Kā vēlētos palīdzēt?', 'multiple', '3', '2', 'garden', 'Dārza darbi', '2', '66.67'], $byOption['garden']);
        $this->assertSame(['empty', 'Jautājums bez atbildēm', 'single', '0', '5', 'unseen', 'Vēl neizvēlēts', '0', '0.00'], $byOption['unseen']);

        foreach ([self::PRIVATE_TEXT, 'PRIVATE-TEXT-', 'Nosacītais komentārs', 'Papildu doma', $admin->email, $invitation->label, $invitation->token, $invitation->token_hash, ...$responseIds] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $csv);
        }
        $this->assertNotContains('note', array_column($aggregates, 0));
        $this->assertNotContains('conditional', array_column($aggregates, 0));
    }

    public function test_csv_neutralizes_all_formula_prefixes_after_whitespace_and_round_trips_punctuation(): void
    {
        $school = $this->school();
        $dangerousValues = ['=1+1', " \t+SUM(1;2)", "\t-1+2", "\r\n@SUM(1;2)", "\u{00A0}\u{FEFF}=1+1"];
        $punctuation = "Jā, \"noteikti\";\notrā rinda";
        $questions = $this->questions();
        $questions[0]['key'] = " \t=question";
        $questions[0]['title'] = "\t+Question";
        $questions[0]['options'] = array_map(
            fn (string $value): array => ['value' => $value, 'label' => $value],
            [...$dangerousValues, $punctuation],
        );
        $questions[0]['allow_other'] = false;
        $survey = $this->survey($school, ['title' => ' =HYPERLINK("example")', 'questions' => [$questions[0]]]);
        for ($index = 0; $index < 5; $index++) {
            SurveyResponse::create(['survey_id' => $survey->id, 'answers' => [" \t=question" => ['value' => $dangerousValues[$index]]]]);
        }
        $this->actingAs($this->user($school));

        $response = $this->get(route('reports.export', $survey))->assertOk()->assertStreamed();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame("'".$survey->title, $rows[0][1]);
        $header = array_search('Jautājuma kods', array_column($rows, 0), true);
        $this->assertNotFalse($header);
        $aggregates = array_slice($rows, $header + 1);

        foreach ($dangerousValues as $index => $value) {
            $this->assertSame("' \t=question", $aggregates[$index][0]);
            $this->assertSame("'\t+Question", $aggregates[$index][1]);
            $this->assertSame("'".$value, $aggregates[$index][5]);
            $this->assertSame("'".$value, $aggregates[$index][6]);
        }
        $this->assertSame($punctuation, $aggregates[5][5]);
        $this->assertSame($punctuation, $aggregates[5][6]);
    }

    public function test_livewire_refresh_rechecks_admin_authorization(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, 5);
        $admin = $this->user($school);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($admin)->test(SurveyReport::class, ['record' => $survey->id])->assertOk();

        $admin->forceFill(['is_admin' => false])->save();
        $component->call('$refresh')->assertForbidden();
    }

    private function school(): School
    {
        return School::create(['name' => 'Testa iestāde', 'slug' => 'testa-'.Str::uuid()]);
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
            'title' => 'Testa aptauja',
            'status' => 'open',
            'sections' => [['key' => 'general', 'title' => 'Vispārīgi']],
            'questions' => $this->questions(),
        ], $attributes));
    }

    private function questions(): array
    {
        return [
            ['key' => 'choice', 'section' => 'general', 'title' => 'Vai vēlaties jaunumus?', 'type' => 'single', 'allow_other' => true, 'options' => [
                ['value' => 'yes', 'label' => 'Jā, noteikti'],
                ['value' => 'no', 'label' => 'Nē'],
                ['value' => 'unused', 'label' => 'Vēl neesmu izlēmis'],
                ['value' => 'other', 'label' => 'Cits'],
            ]],
            ['key' => 'multi', 'section' => 'general', 'title' => 'Kā vēlētos palīdzēt?', 'type' => 'multiple', 'options' => [
                ['value' => 'garden', 'label' => 'Dārza darbi'],
                ['value' => 'books', 'label' => 'Grāmatas'],
                ['value' => 'nothing', 'label' => 'Šoreiz nevaru'],
            ]],
            ['key' => 'note', 'section' => 'general', 'title' => 'Papildu doma', 'type' => 'text', 'options' => []],
            ['key' => 'conditional', 'section' => 'general', 'title' => 'Nosacītais komentārs', 'type' => 'text', 'options' => [], 'condition' => ['question' => 'choice', 'values' => ['no']]],
            ['key' => 'empty', 'section' => 'general', 'title' => 'Jautājums bez atbildēm', 'type' => 'single', 'options' => [['value' => 'unseen', 'label' => 'Vēl neizvēlēts']]],
        ];
    }

    private function responses(Survey $survey, int $count): array
    {
        $answers = [
            ['choice' => ['value' => 'yes'], 'multi' => ['value' => ['garden', 'books']], 'note' => ['value' => self::PRIVATE_TEXT]],
            ['choice' => ['value' => 'yes'], 'multi' => ['value' => ['garden']], 'note' => ['value' => 'PRIVATE-TEXT-SECOND']],
            ['choice' => ['value' => 'no'], 'multi' => ['value' => ['books']], 'conditional' => ['value' => 'PRIVATE-TEXT-CONDITIONAL']],
            ['choice' => ['value' => 'yes']],
            [],
        ];
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = SurveyResponse::create(['survey_id' => $survey->id, 'answers' => $answers[$index % count($answers)]])->id;
        }

        return $ids;
    }

    private function invitation(Survey $survey): FamilyInvitation
    {
        return FamilyInvitation::create(['school_id' => $survey->school_id, 'survey_id' => $survey->id, 'label' => 'PRIVATE-FAMILY-LABEL']);
    }

    private function reportUrl(Survey $survey): string
    {
        return '/admin/aptaujas/'.$survey->getRouteKey().'/atskaite';
    }

    private function reportAction(string $html, string $label): DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $actions = (new DOMXPath($document))->query('//*[contains(@class, "mikausis-report-actions")]/*[normalize-space(.)="'.$label.'"]');
        $this->assertCount(1, $actions);
        $action = $actions->item(0);
        $this->assertInstanceOf(DOMElement::class, $action);

        return $action;
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
