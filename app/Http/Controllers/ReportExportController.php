<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyReportService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, Survey $survey, SurveyReportService $reports): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
                && $user->is_admin
                && $user->school_id !== null
                && $user->school()->exists()
                && (int) $user->school_id === (int) $survey->school_id,
            403,
        );

        $report = $reports->build($survey);

        abort_if(
            $report['suppressed']
                || $report['response_count'] < max(5, (int) $report['minimum_responses']),
            403,
            'Eksports pieejams pēc vismaz piecām iesniegtām atbildēm.',
        );

        return response()->streamDownload(function () use ($survey, $report): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new RuntimeException('Neizdevās atvērt CSV izvadi.');
            }

            try {
                // UTF-8 BOM makes Latvian text readable in spreadsheet applications.
                fwrite($output, "\xEF\xBB\xBF");

                $write = static function (array $cells) use ($output): void {
                    fputcsv($output, array_map(self::csvCell(...), $cells), ';', '"', '');
                };

                $write(['Aptauja', $survey->title]);
                $write(['Iesniegtas anketas', $report['response_count']]);
                $write(['Ģimeņu uzaicinājumi', $report['invitations_count']]);
                $write(['Atzīmētas dalības', $report['participation_count']]);
                $write(['Procentu saucējs', 'Uz attiecīgo jautājumu atbildējušo skaits. Vairāku izvēļu procentu summa var pārsniegt 100%.']);
                $write(['Saturs', 'Apkopoti atbilžu varianti. Brīvais teksts CSV failā netiek iekļauts.']);
                $write([]);
                $write(['Jautājuma kods', 'Jautājums', 'Veids', 'Atbildējuši', 'Izlaiduši', 'Varianta kods', 'Atbildes variants', 'Skaits', 'Procenti (%)']);

                // Explicit allowlist: never export response records, free text or invitation identifiers.
                foreach ($report['questions'] as $question) {
                    foreach ($question['options'] as $option) {
                        $write([
                            $question['key'],
                            $question['title'].(($question['archived'] ?? false) ? ' (iepriekšējā versija)' : ''),
                            $question['type'],
                            $question['answered'],
                            $question['skipped'],
                            $option['value'],
                            $option['label'],
                            $option['count'],
                            number_format((float) $option['percent'], 2, '.', ''),
                        ]);
                    }
                }
            } finally {
                fclose($output);
            }
        }, 'aptaujas-atskaite-'.$survey->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function csvCell(string|int|float|null $value): string
    {
        $cell = (string) $value;

        // Check past whitespace, control and formatting characters, preserving the original text.
        return preg_match('/\A[\s\p{Z}\p{Cf}\x00-\x20]*[=+\-@]/u', $cell) === 1
            ? "'".$cell
            : $cell;
    }
}
