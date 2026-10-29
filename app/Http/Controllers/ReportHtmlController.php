<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportHtmlController extends Controller
{
    public function show(Request $request, Survey $survey, SurveyReportService $reports): Response
    {
        return $this->reportResponse($request, $survey, $reports);
    }

    public function download(Request $request, Survey $survey, SurveyReportService $reports): Response
    {
        return $this->reportResponse($request, $survey, $reports, download: true);
    }

    private function reportResponse(Request $request, Survey $survey, SurveyReportService $reports, bool $download = false): Response
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

        $report = $reports->build($survey, includeAnswerRows: true);

        abort_if(
            $report['suppressed']
                || $report['response_count'] < max(5, (int) $report['minimum_responses']),
            403,
            'Drukas skats un HTML eksports pieejams pēc vismaz piecām iesniegtām atbildēm.',
        );

        $response = response()->view('reports.html', [
            'survey' => $survey,
            'report' => $report,
            'logo' => $this->embeddedAsset('images/mikausis.svg', 'image/svg+xml'),
            'fontLatin' => $this->embeddedAsset('fonts/montserrat-latin.woff2', 'font/woff2'),
            'fontLatinExt' => $this->embeddedAsset('fonts/montserrat-latin-ext.woff2', 'font/woff2'),
        ], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-transform, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Frame-Options' => 'DENY',
        ]);

        if ($download) {
            $response->headers->set('Content-Disposition', 'attachment; filename="aptaujas-atskaite-'.$survey->getKey().'.html"');
        }

        return $response;
    }

    private function embeddedAsset(string $path, string $mime): ?string
    {
        $file = public_path($path);

        return is_file($file) ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file)) : null;
    }
}
