<?php

namespace App\Http\Controllers;

use App\Models\FamilyInvitation;
use App\Models\Survey;
use App\Services\SurveySubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SurveyController extends Controller
{
    public function index(): View
    {
        $surveys = Survey::query()->whereHas('school', fn ($query) => $query->where('slug', 'mikausis'))
            ->where('status', 'open')->whereNotNull('published_at')
            ->where(fn ($query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->latest('published_at')->get();

        return view('surveys.index', compact('surveys'));
    }

    public function show(string $slug, ?string $token = null): View
    {
        $survey = $this->publicSurvey($slug);
        $invitation = $this->invitation($survey, $token);
        $session = request()->session();
        $submissionKey = 'survey_submission_ids.'.$survey->id;
        if (! $session->has($submissionKey)
            || ($invitation !== null && $survey->responses()->whereKey($session->get($submissionKey))->exists())) {
            $session->put($submissionKey, (string) Str::uuid());
        }

        return view('surveys.show', [
            'survey' => $survey,
            'invitation' => $invitation,
            'submissionId' => $session->get($submissionKey),
            'submitted' => (bool) $session->get('survey_submitted.'.$survey->id, false),
        ]);
    }

    public function store(Request $request, string $slug, ?string $token = null): Response|JsonResponse|RedirectResponse
    {
        $survey = $this->publicSurvey($slug);
        $invitation = $this->invitation($survey, $token);
        $session = $request->session();
        $submissionKey = 'survey_submission_ids.'.$survey->id;
        $expected = $session->get($submissionKey);

        try {
            $provided = $request->input('submission_id');
            if (! is_string($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
                throw ValidationException::withMessages(['submission_id' => 'Lūdzu, atveriet aptauju vēlreiz un mēģiniet vēlreiz.']);
            }

            app(SurveySubmissionService::class)->submit($survey, $request->only(['submission_id', 'form_definition', 'answers', 'other_answers', 'role', 'anonymous', 'respondent', 'respondent_name', 'respondent_role']), $invitation);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $survey->refresh();

            if (! is_string($expected) || ! Str::isUuid($expected)) {
                $expected = (string) Str::uuid();
                $session->put($submissionKey, $expected);
            }

            return response()->view('surveys.show', [
                'survey' => $survey,
                'invitation' => $invitation,
                'submissionId' => $expected,
                'submitted' => (bool) $session->get('survey_submitted.'.$survey->id, false),
                'oldValues' => $request->only(['answers', 'other_answers', 'role', 'anonymous', ...(! in_array($request->input('anonymous'), [false, 0, '0'], true) ? [] : ['respondent', 'respondent_name', 'respondent_role'])]),
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($exception->errors())),
            ], 422);
        }

        $session->put('survey_submitted.'.$survey->id, true);
        $redirect = route('surveys.thanks', ['slug' => $survey->slug]);

        return $request->expectsJson() ? response()->json(['redirect' => $redirect]) : redirect()->to($redirect);
    }

    public function thanks(string $slug): View
    {
        return view('surveys.thanks', ['survey' => $this->publicSurvey($slug)]);
    }

    private function publicSurvey(string $slug): Survey
    {
        return Survey::query()->where('slug', $slug)->whereIn('status', ['open', 'closed'])->whereNotNull('published_at')->firstOrFail();
    }

    private function invitation(Survey $survey, ?string $token): ?FamilyInvitation
    {
        if ($token === null) {
            return null;
        }

        return FamilyInvitation::query()->forSchool((int) $survey->school_id)
            ->where('survey_id', $survey->id)->where('token_hash', hash('sha256', $token))->firstOrFail();
    }
}
