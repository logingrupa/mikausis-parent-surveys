<?php

namespace Tests\Feature;

use App\Models\FamilyInvitation;
use App\Models\School;
use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyReportService;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class HtmlReportTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOM_KEY = 'detail_94a8f720-25c3-4f6b-a2af-e506f2769e67'; // gitleaks:allow -- synthetic field identifier

    private const ROLE_KEY = 'detail_33876f42-edc3-4459-bd81-1ed39308f57d'; // gitleaks:allow -- synthetic field identifier

    private const FOREIGN_SECRET = 'FOREIGN-SCHOOL-PRIVATE-ANSWER';

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

    public static function htmlEndpoints(): array
    {
        return ['print view' => ['reports.html'], 'download' => ['reports.html.download']];
    }

    #[DataProvider('htmlEndpoints')]
    public function test_guests_must_log_in(string $endpoint): void
    {
        $survey = $this->survey($this->school());

        $this->get(route($endpoint, $survey))->assertRedirect(url('/admin/login'));
        $this->getJson(route($endpoint, $survey))->assertUnauthorized();
    }

    #[DataProvider('htmlEndpoints')]
    public function test_non_admins_cannot_access_html_reports(string $endpoint): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $this->actingAs($this->user($school, false));

        $this->get(route($endpoint, $survey))->assertForbidden()->assertDontSee('Pirmā doma');
    }

    #[DataProvider('htmlEndpoints')]
    public function test_admins_without_a_school_cannot_access_html_reports(string $endpoint): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey);
        $this->actingAs($this->user(null));

        $this->get(route($endpoint, $survey))->assertForbidden()->assertDontSee('Pirmā doma');
    }

    #[DataProvider('htmlEndpoints')]
    public function test_a_stale_school_id_does_not_authorize_an_admin(string $endpoint): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $admin = $this->user($school);
        $admin->school_id = 999999;
        $this->actingAs($admin);

        $this->get(route($endpoint, $survey))->assertForbidden()->assertDontSee('Pirmā doma');
    }

    #[DataProvider('htmlEndpoints')]
    public function test_admins_cannot_access_another_schools_html_report(string $endpoint): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey);
        $this->actingAs($this->user($this->school()));

        $this->get(route($endpoint, $survey))
            ->assertForbidden()
            ->assertDontSee($survey->title)
            ->assertDontSee('Pirmā doma')
            ->assertDontSee('mara.private@example.test');
    }

    #[DataProvider('htmlEndpoints')]
    public function test_missing_surveys_return_not_found(string $endpoint): void
    {
        $this->actingAs($this->user($this->school()));

        $this->get(route($endpoint, ['survey' => 999999]))->assertNotFound();
    }

    public static function suppressedCounts(): array
    {
        return ['no responses' => [0], 'below minimum' => [4]];
    }

    #[DataProvider('suppressedCounts')]
    public function test_both_html_endpoints_suppress_all_report_content_below_five_responses(int $count): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey, $count);
        $this->actingAs($this->user($school));

        foreach (array_column(self::htmlEndpoints(), 0) as $endpoint) {
            $this->get(route($endpoint, $survey))
                ->assertForbidden()
                ->assertDontSee($survey->title)
                ->assertDontSee('Vai saņemt ziņas?')
                ->assertDontSee('Jā, vēlos jaunumus')
                ->assertDontSee('Pirmā doma')
                ->assertDontSee('Māra Ozola')
                ->assertDontSee('mara.private@example.test');
        }

        $report = app(SurveyReportService::class)->build($survey, true);
        $this->assertTrue($report['suppressed']);
        $this->assertSame([], $report['questions']);
    }

    #[DataProvider('htmlEndpoints')]
    public function test_html_groups_each_question_summary_and_unordered_answers_in_survey_order(string $endpoint): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $invitation = FamilyInvitation::create([
            'school_id' => $school->id, 'survey_id' => $survey->id, 'label' => 'PRIVATE-FAMILY-LABEL',
        ]);
        $foreign = $this->survey($this->school());
        $foreign->responses()->create([
            'answers' => ['note' => ['value' => self::FOREIGN_SECRET]],
            'respondent_details' => ['fields' => [['key' => 'email', 'label' => 'FOREIGN-FIELD', 'type' => 'email', 'value' => 'foreign@example.test']]],
        ]);
        // A loaded relation must not bypass the actual survey-scoped response query.
        $survey->setRelation('responses', collect());
        $this->actingAs($this->user($school));

        $response = $this->get(route($endpoint, $survey))->assertOk();
        $response->assertSee('<!--email_off-->', false)->assertSee('<!--/email_off-->', false);
        $xpath = $this->xpath($response->getContent());
        $sections = $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " question ")]');
        $this->assertCount(count($survey->questions), $sections);
        $expectedRows = $this->expectedRows();

        foreach ($sections as $index => $section) {
            $question = $survey->questions[$index];
            $heading = $xpath->query('./h2', $section)->item(0);
            $this->assertInstanceOf(DOMElement::class, $heading);
            $this->assertSame($question['title'], trim($heading->textContent));
            $totals = $xpath->query('./dl', $section)->item(0);
            $this->assertInstanceOf(DOMElement::class, $totals);
            $this->assertSame((string) count($expectedRows[$question['key']]), trim($xpath->query('.//dd', $totals)->item(0)->textContent));
            $this->assertSame((string) (5 - count($expectedRows[$question['key']])), trim($xpath->query('.//dd', $totals)->item(1)->textContent));
            $this->assertBefore($heading, $totals);

            $rows = $this->htmlRows($xpath, $section);
            $this->assertSameRows($expectedRows[$question['key']], $rows);
            $this->assertCount(0, $xpath->query('.//ol', $section));

            if ($rows !== []) {
                $list = $xpath->query('./ul', $section)->item(0);
                $this->assertInstanceOf(DOMElement::class, $list);
                $this->assertBefore($totals, $list);
                if ($question['type'] !== 'text') {
                    $summary = $xpath->query('./table', $section)->item(0);
                    $this->assertInstanceOf(DOMElement::class, $summary);
                    $this->assertBefore($heading, $summary);
                    $this->assertBefore($summary, $list);
                }
            }
        }

        $this->assertStringContainsString('33,33%', $sections->item(0)->textContent);
        $this->assertStringContainsString('66,67%', $sections->item(1)->textContent);
        $this->assertStringContainsString('Procentu saucējs: 3 atbildējušie.', $sections->item(1)->textContent);
        $this->assertStringContainsString('Uz šo jautājumu vēl nav atbilžu.', $sections->item(4)->textContent);

        // Fetch only identifiers for leakage checks, never serialize full response models into HTML.
        foreach ([...$survey->responses()->pluck('id')->all(), $invitation->label, $invitation->token, $invitation->token_hash,
            self::CUSTOM_KEY, self::ROLE_KEY, self::FOREIGN_SECRET, 'FOREIGN-FIELD', 'foreign@example.test', 'PRIVATE-SUBMISSION-ID', 'PRIVATE-TIMESTAMP',
            'UNRECOGNIZED-CHOICE', 'UNSELECTED-OTHER', 'UNSELECTED-MULTI', 'ORPHAN-OTHER'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }

        $this->assertSame('/admin/aptaujas/'.$survey->id.'/html', parse_url(route('reports.html', $survey), PHP_URL_PATH));
        $this->assertSame('/admin/aptaujas/'.$survey->id.'/html/lejupielade', parse_url(route('reports.html.download', $survey), PHP_URL_PATH));
    }

    public function test_service_opt_in_rows_preserve_answers_and_only_display_labels_and_values(): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey);

        $report = app(SurveyReportService::class)->build($survey, true);
        $this->assertFalse($report['suppressed']);
        $this->assertSame(5, $report['response_count']);
        $this->assertSame(array_column($survey->questions, 'key'), array_column($report['questions'], 'key'));

        foreach ($report['questions'] as $question) {
            $this->assertSameRows($this->expectedRows()[$question['key']], $question['answer_rows']);
            foreach ($question['answer_rows'] as $row) {
                $this->assertSame(['answer', 'respondent'], array_keys($row));
                if ($row['respondent'] !== null) {
                    $this->assertSame(['fields'], array_keys($row['respondent']));
                    foreach ($row['respondent']['fields'] as $field) {
                        $this->assertSame(['label', 'value'], array_keys($field));
                    }
                }
            }
        }

        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([...$survey->responses()->pluck('id')->all(), self::CUSTOM_KEY, self::ROLE_KEY, 'PRIVATE-SUBMISSION-ID', 'PRIVATE-TIMESTAMP'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
    }

    public function test_default_and_explicit_false_service_calls_keep_the_original_aggregate_contract_after_opt_in(): void
    {
        $survey = $this->survey($this->school());
        $this->responses($survey);
        $service = app(SurveyReportService::class);
        $service->build($survey, true);

        foreach ([$service->build($survey), $service->build($survey, false)] as $report) {
            $this->assertSame(['response_count', 'minimum_responses', 'suppressed', 'questions', 'invitations_count', 'participation_count'], array_keys($report));
            foreach ($report['questions'] as $question) {
                $this->assertSame(['key', 'title', 'type', 'answered', 'skipped', 'options', 'texts'], array_keys($question));
                foreach ($question['options'] as $option) {
                    $this->assertSame(['value', 'label', 'count', 'percent'], array_keys($option));
                }
            }
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach (['Māra Ozola', 'mara.private@example.test', 'Vēlamais saziņas laiks', self::CUSTOM_KEY, 'respondent', 'answer_rows'] as $privateValue) {
                $this->assertStringNotContainsString($privateValue, $json);
            }
        }
    }

    public function test_duplicate_answers_keep_their_own_metadata_and_empty_details_stay_anonymous(): void
    {
        $survey = $this->survey($this->school(), ['questions' => [['key' => 'note', 'title' => 'Domas', 'type' => 'text', 'options' => []]]]);
        foreach ([
            ['value' => 'Vienāda doma', 'details' => $this->details('mother')],
            ['value' => 'Vienāda doma', 'details' => null],
            ['value' => 'Trešā doma', 'details' => ['fields' => []]],
            ['value' => 'Ceturtā doma', 'details' => ['fields' => [['key' => 'invalid', 'label' => ' ', 'type' => 'text', 'value' => 'ignored']]]],
            ['value' => 'Piektā doma', 'details' => ['name' => ' Agrākais respondents ', 'role' => 'guardian']],
        ] as $row) {
            $survey->responses()->create(['answers' => ['note' => ['value' => $row['value']]], 'respondent_details' => $row['details']]);
        }

        $rows = app(SurveyReportService::class)->build($survey, true)['questions'][0]['answer_rows'];
        $this->assertSameRows([
            ['answer' => 'Vienāda doma', 'respondent' => $this->displayDetails('mother')],
            ['answer' => 'Vienāda doma', 'respondent' => null],
            ['answer' => 'Trešā doma', 'respondent' => null],
            ['answer' => 'Ceturtā doma', 'respondent' => null],
            ['answer' => 'Piektā doma', 'respondent' => ['fields' => [
                ['label' => 'Vārds un uzvārds', 'value' => 'Agrākais respondents'],
                ['label' => 'Loma', 'value' => 'Cits aizbildnis'],
            ]]],
        ], $rows);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_anonymous_answer_order_is_shuffled_per_question_and_per_build_with_a_fixed_seed(): void
    {
        $survey = $this->survey($this->school(), ['questions' => [
            ['key' => 'first', 'title' => 'Pirmais jautājums', 'type' => 'text', 'options' => []],
            ['key' => 'second', 'title' => 'Otrais jautājums', 'type' => 'text', 'options' => []],
        ]]);
        for ($index = 0; $index < 8; $index++) {
            $survey->responses()->create(['answers' => ['first' => ['value' => 'A'.$index], 'second' => ['value' => 'B'.$index]]]);
        }

        // shuffle() uses MT state. Isolate the seed so other tests' random state is untouched.
        mt_srand(20261029, MT_RAND_MT19937);
        $orders = [];
        for ($call = 0; $call < 3; $call++) {
            $report = app(SurveyReportService::class)->build($survey, true);
            foreach ($report['questions'] as $questionIndex => $question) {
                $prefix = $questionIndex === 0 ? 'A' : 'B';
                $expected = array_map(fn (int $index): array => ['answer' => $prefix.$index, 'respondent' => null], range(0, 7));
                $this->assertSameRows($expected, $question['answer_rows']);
                $orders[$call][$questionIndex] = array_map(fn (array $row): int => (int) substr($row['answer'], 1), $question['answer_rows']);
            }
        }

        $this->assertNotSame($orders[0][0], $orders[0][1], 'Questions must not reuse a shared respondent permutation.');
        $this->assertNotSame($orders[0][0], $orders[1][0], 'Subsequent builds must not reuse a fixed response order.');
        $this->assertNotSame($orders[0][1], $orders[2][1], 'A later question must also receive a fresh permutation.');
    }

    #[DataProvider('htmlEndpoints')]
    public function test_html_is_self_contained_and_has_a_print_button_and_a4_print_styles(string $endpoint): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $this->actingAs($this->user($school));

        $response = $this->get(route($endpoint, $survey))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $css = $xpath->evaluate('string(//head/style)');
        $this->assertMatchesRegularExpression('/@media\s+print\s*\{/i', $css);
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*\bsize\s*:\s*A4\s*;/i', $css);
        $this->assertMatchesRegularExpression('/@media\s+print\s*\{.*?\.print-tools\s*\{[^}]*display\s*:\s*none\b/is', $css);
        $this->assertStringContainsString('@font-face', $css);
        preg_match_all('/url\(\s*[\'\"]?([^\s\'\"\)]+)/i', $css, $urls);
        $this->assertNotEmpty($urls[1], 'Local fonts must be embedded as data URLs.');
        foreach ($urls[1] as $url) {
            $this->assertMatchesRegularExpression('/\Adata:font\/woff2;base64,[A-Za-z0-9+\/=]+\z/', $url);
        }
        $this->assertDoesNotMatchRegularExpression('/@import\b/i', $css);
        $this->assertCount(1, $xpath->query('//button[@onclick="window.print()"]'));
        $this->assertCount(1, $xpath->query('//img[starts-with(@src, "data:image/")]'));
        $this->assertCount(0, $xpath->query('//script | //link[@href] | //iframe | //object | //embed | //base | //video | //audio | //source'));
        foreach ($xpath->query('//*[@src or @srcset or @poster or @background]') as $resource) {
            $this->assertStringStartsWith('data:', $resource->getAttribute('src'));
            $this->assertFalse($resource->hasAttribute('srcset'));
            $this->assertFalse($resource->hasAttribute('poster'));
            $this->assertFalse($resource->hasAttribute('background'));
        }
    }

    #[DataProvider('htmlEndpoints')]
    public function test_hostile_titles_questions_choices_answers_and_respondent_fields_are_escaped(string $endpoint): void
    {
        $school = $this->school();
        $title = 'Aptauja </title><script id="hostile-title">alert("title")</script>';
        $question = 'Jautājums <img id="hostile-question" src=x onerror=alert(1)>';
        $choice = 'Izvēle <svg id="hostile-choice" onload=alert(2)>';
        $text = 'Doma <script id="hostile-text">alert("text")</script> & "citāts"';
        $name = 'Vārds <img id="hostile-name" src=x onerror=alert(3)>';
        $label = 'Lauks <svg id="hostile-label" onload=alert(4)>';
        $other = 'Cits <iframe id="hostile-other" src="https://example.test">';
        $survey = $this->survey($school, ['title' => $title, 'questions' => [
            ['key' => 'choice', 'title' => $question, 'type' => 'single', 'allow_other' => true, 'options' => [['value' => 'yes', 'label' => $choice]]],
            ['key' => 'note', 'title' => 'Domas', 'type' => 'text', 'options' => []],
        ]]);
        for ($index = 0; $index < 5; $index++) {
            $survey->responses()->create([
                'answers' => ['choice' => ['value' => $index === 0 ? 'other' : 'yes', 'other' => $other], 'note' => ['value' => $text]],
                'respondent_details' => ['fields' => [['key' => self::CUSTOM_KEY, 'label' => $label, 'type' => 'text', 'value' => $name]]],
            ]);
        }
        $this->actingAs($this->user($school));

        $response = $this->get(route($endpoint, $survey))->assertOk();
        foreach ([$title, $question, $choice, $text, $name, $label, $other] as $hostile) {
            $response->assertSee($hostile)->assertDontSee($hostile, false);
        }
        $response->assertDontSee(self::CUSTOM_KEY, false);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(0, $xpath->query('//script | //svg | //iframe | //*[@onerror or @onload] | //*[starts-with(@id, "hostile-")]'));
        $this->assertSame($title, $xpath->evaluate('string(//h1)'));
        $this->assertSame($question, $xpath->evaluate('string(//section[contains(@class, "question")]/h2)'));
        $answerTexts = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query('//p[@class="answer-text"]')));
        $this->assertContains($text, $answerTexts);
        $this->assertContains('Cits: '.$other, $answerTexts);
    }

    #[DataProvider('htmlEndpoints')]
    public function test_html_responses_have_private_headers_and_download_has_the_expected_filename(string $endpoint): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $this->actingAs($this->user($school));

        $response = $this->get(route($endpoint, $survey))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-transform'));

        if ($endpoint === 'reports.html.download') {
            $response->assertDownload('aptaujas-atskaite-'.$survey->id.'.html');
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        } else {
            $response->assertHeaderMissing('Content-Disposition');
        }
    }

    public function test_csv_allowlist_excludes_generic_respondent_fields_even_from_an_enriched_report(): void
    {
        $school = $this->school();
        $survey = $this->survey($school);
        $this->responses($survey);
        $report = app(SurveyReportService::class)->build($survey, true);
        // Exercise the exporter's allowlist even if a caller supplies the HTML-only fields.
        $this->mock(SurveyReportService::class)->shouldReceive('build')->once()
            ->with(\Mockery::on(fn (Survey $actual): bool => $actual->is($survey)))
            ->andReturn($report);
        $this->actingAs($this->user($school));

        $response = $this->get(route('reports.export', $survey))
            ->assertOk()->assertStreamed()->assertDownload('aptaujas-atskaite-'.$survey->id.'.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Jā, vēlos jaunumus', $csv);
        $this->assertStringContainsString('66.67', $csv);
        foreach (['Māra Ozola', 'Pēteris Ozols', 'Ilze Aizbildne', 'mara.private@example.test', 'Vēlamais saziņas laiks',
            'Pēc plkst. 18.00', 'Saistība ar bērnu', 'Pašizvēlēts kods', self::CUSTOM_KEY, self::ROLE_KEY,
            'Pirmā doma', 'Otrā doma', 'Atzvanīt', 'Palīdzība koncertā', ...$survey->responses()->pluck('id')->all()] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $csv);
        }
    }

    private function school(): School
    {
        return School::create(['name' => 'Testa iestāde', 'slug' => 'html-report-'.Str::uuid()]);
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
            'title' => 'Drukājamā testa aptauja',
            'status' => 'open',
            'allow_identified_responses' => true,
            'respondent_fields' => [
                ['key' => 'name', 'label' => 'Vecāka vārds', 'type' => 'text', 'required' => false],
                ['key' => 'role', 'label' => 'Saistība ar bērnu', 'type' => 'role', 'required' => false],
                ['key' => 'email', 'label' => 'Saziņas e-pasts', 'type' => 'email', 'required' => false],
                ['key' => self::CUSTOM_KEY, 'label' => 'Vēlamais saziņas laiks', 'type' => 'text', 'required' => false],
                ['key' => self::ROLE_KEY, 'label' => 'Pārstāvja loma', 'type' => 'role', 'required' => false],
            ],
            'sections' => [['key' => 'general', 'title' => 'Vispārīgi']],
            'questions' => [
                ['key' => 'z_choice', 'section' => 'general', 'title' => 'Vai saņemt ziņas?', 'type' => 'single', 'allow_other' => true, 'options' => [
                    ['value' => 'yes', 'label' => 'Jā, vēlos jaunumus'], ['value' => 'no', 'label' => 'Nē, paldies'],
                    ['value' => 'unused', 'label' => 'Vēl domāju'], ['value' => 'other', 'label' => 'Cita izvēle'],
                ]],
                ['key' => 'a_multi', 'section' => 'general', 'title' => 'Kā palīdzēt?', 'type' => 'multiple', 'allow_other' => true, 'options' => [
                    ['value' => 'garden', 'label' => 'Dārza darbi'], ['value' => 'books', 'label' => 'Grāmatas'],
                ]],
                ['key' => 'note', 'section' => 'general', 'title' => 'Papildu doma', 'type' => 'text', 'options' => []],
                ['key' => 'conditional', 'section' => 'general', 'title' => 'Nosacītais komentārs', 'type' => 'text', 'options' => [], 'condition' => ['question' => 'z_choice', 'values' => ['no']]],
                ['key' => 'empty', 'section' => 'general', 'title' => 'Bez atbildēm', 'type' => 'single', 'options' => [['value' => 'unseen', 'label' => 'Vēl neizvēlēts']]],
            ],
        ], $attributes));
    }

    private function responses(Survey $survey, int $count = 5): void
    {
        $answers = [
            ['z_choice' => ['value' => 'yes', 'other' => 'UNSELECTED-OTHER'], 'a_multi' => ['value' => ['books', 'garden', 'books', 'UNRECOGNIZED-CHOICE']], 'note' => ['value' => '  Pirmā doma  ']],
            ['z_choice' => ['value' => 'no'], 'a_multi' => ['value' => ['garden', 'other'], 'other' => '  Palīdzība koncertā  '], 'note' => ['value' => 'Otrā doma']],
            ['z_choice' => ['value' => 'other', 'other' => '  Atzvanīt  '], 'a_multi' => ['value' => ['books'], 'other' => 'UNSELECTED-MULTI'], 'note' => ['value' => '   '], 'conditional' => ['value' => 'Papildu skaidrojums']],
            ['z_choice' => ['value' => 'UNRECOGNIZED-CHOICE', 'other' => 'ORPHAN-OTHER'], 'a_multi' => ['value' => ['UNRECOGNIZED-CHOICE']], 'note' => ['value' => 'Aizbildnes doma']],
            ['z_choice' => ['value' => ''], 'a_multi' => ['value' => []], 'note' => ['value' => 42]],
        ];
        foreach (array_slice($answers, 0, $count) as $index => $answer) {
            $survey->responses()->create([
                'answers' => $answer,
                'respondent_details' => match ($index) {
                    0 => $this->details('mother'), 2 => $this->details('father'), 3 => $this->details('guardian'), default => null,
                },
            ]);
        }
    }

    private function details(string $role): array
    {
        $name = match ($role) {
            'mother' => 'Māra Ozola', 'father' => 'Pēteris Ozols', 'guardian' => 'Ilze Aizbildne'
        };
        $fields = [
            ['key' => 'name', 'label' => 'Vecāka vārds', 'type' => 'text', 'value' => $name],
            ['key' => 'role', 'label' => 'Saistība ar bērnu', 'type' => 'role', 'value' => $role],
        ];
        if ($role === 'mother') {
            $fields = [...$fields,
                ['key' => 'email', 'label' => 'Saziņas e-pasts', 'type' => 'email', 'value' => 'mara.private@example.test'],
                ['key' => self::CUSTOM_KEY, 'label' => 'Vēlamais saziņas laiks', 'type' => 'text', 'value' => 'Pēc plkst. 18.00'],
                ['key' => 'custom_code', 'label' => 'Pašizvēlēts kods', 'type' => 'text', 'value' => 'father'],
                ['key' => self::ROLE_KEY, 'label' => 'Pārstāvja loma', 'type' => 'role', 'value' => 'guardian'],
            ];
        }

        return ['fields' => $fields, 'submission_id' => 'PRIVATE-SUBMISSION-ID', 'submitted_at' => 'PRIVATE-TIMESTAMP'];
    }

    private function displayDetails(string $role): array
    {
        $fields = [];
        foreach ($this->details($role)['fields'] as $field) {
            $value = $field['type'] === 'role'
                ? match ($field['value']) {
                    'mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'
                }
            : $field['value'];
            $fields[] = ['label' => $field['label'], 'value' => $value];
        }

        return ['fields' => $fields];
    }

    private function expectedRows(): array
    {
        $mother = $this->displayDetails('mother');
        $father = $this->displayDetails('father');

        return [
            'z_choice' => [
                ['answer' => 'Jā, vēlos jaunumus', 'respondent' => $mother],
                ['answer' => 'Nē, paldies', 'respondent' => null],
                ['answer' => 'Cita izvēle: Atzvanīt', 'respondent' => $father],
            ],
            'a_multi' => [
                ['answer' => 'Dārza darbi; Grāmatas', 'respondent' => $mother],
                ['answer' => 'Dārza darbi; Cits: Palīdzība koncertā', 'respondent' => null],
                ['answer' => 'Grāmatas', 'respondent' => $father],
            ],
            'note' => [
                ['answer' => 'Pirmā doma', 'respondent' => $mother],
                ['answer' => 'Otrā doma', 'respondent' => null],
                ['answer' => 'Aizbildnes doma', 'respondent' => $this->displayDetails('guardian')],
            ],
            'conditional' => [['answer' => 'Papildu skaidrojums', 'respondent' => $father]],
            'empty' => [],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function htmlRows(DOMXPath $xpath, DOMNode $section): array
    {
        $rows = [];
        foreach ($xpath->query('./ul[@class="answers"]/li', $section) as $row) {
            $respondent = $xpath->query('./*[@class="respondent"]', $row)->item(0);
            $this->assertInstanceOf(DOMElement::class, $respondent);
            $fields = [];
            foreach ($xpath->query('.//dt', $respondent) as $label) {
                $value = $xpath->query('following-sibling::dd[1]', $label)->item(0);
                $this->assertInstanceOf(DOMElement::class, $value);
                $fields[] = ['label' => rtrim(trim($label->textContent), ':'), 'value' => trim($value->textContent)];
            }
            if ($fields === []) {
                $this->assertSame('Anonīmi', trim($respondent->textContent));
            }
            $answer = $xpath->query('./p[@class="answer-text"]', $row)->item(0);
            $this->assertInstanceOf(DOMElement::class, $answer);
            $rows[] = ['answer' => trim($answer->textContent), 'respondent' => $fields === [] ? null : ['fields' => $fields]];
        }

        return $rows;
    }

    private function assertSameRows(array $expected, array $actual): void
    {
        $compare = static fn (array $first, array $second): int => strcmp(
            json_encode($first, JSON_THROW_ON_ERROR),
            json_encode($second, JSON_THROW_ON_ERROR),
        );
        usort($expected, $compare);
        usort($actual, $compare);
        $this->assertSame($expected, $actual);
    }

    private function assertBefore(DOMNode $first, DOMNode $second): void
    {
        $this->assertTrue($first->parentNode->isSameNode($second->parentNode), 'Question content must remain in its own group.');
        for ($sibling = $first->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if ($sibling->isSameNode($second)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail('Expected the question heading or aggregate summary before its answers.');
    }
}
