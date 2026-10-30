<?php

namespace App\Http\Controllers;

use App\Models\FamilyInvitation;
use App\Models\Survey;
use App\Services\SurveySubmissionService;
use App\Support\SurveySubmissionResumeToken;
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
        $draftScope = SurveySubmissionResumeToken::draftScope($survey, $invitation);
        if (! $session->has($submissionKey)
            || $invitation !== null
            || $session->get('survey_submission_scopes.'.$survey->id) !== $draftScope
            || (! $session->get('survey_submitted.'.$survey->id, false)
                && $survey->responses()->whereKey($session->get($submissionKey))->exists())) {
            $session->put($submissionKey, (string) Str::uuid());
        }
        $session->put('survey_submission_scopes.'.$survey->id, $draftScope);

        return view('surveys.show', [
            'survey' => $survey,
            'invitation' => $invitation,
            'submissionId' => $session->get($submissionKey),
            'submissionResumeToken' => SurveySubmissionResumeToken::issue($survey, $invitation, $session->get($submissionKey)),
            'draftScope' => $draftScope,
            'submitted' => (bool) $session->get('survey_submitted.'.$survey->id, false),
        ]);
    }

    public function session(Request $request, string $slug, ?string $token = null): JsonResponse
    {
        abort_unless($request->ajax(), 403);
        abort_if($request->headers->has('Sec-Fetch-Site') && $request->header('Sec-Fetch-Site') !== 'same-origin', 403);
        abort_if($request->headers->has('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost(), 403);

        $survey = $this->publicSurvey($slug);
        $invitation = $this->invitation($survey, $token);
        $data = [];

        if ($request->headers->has('X-Survey-Resume')) {
            $id = SurveySubmissionResumeToken::submissionId($request->header('X-Survey-Resume'), $survey, $invitation);
            $request->session()->put('survey_submission_ids.'.$survey->id, $id);
            $request->session()->put('survey_submission_scopes.'.$survey->id, SurveySubmissionResumeToken::draftScope($survey, $invitation));
            $data['submission_id'] = $id;
            $data['submitted'] = $survey->responses()->whereKey($id)->exists();
            if ($data['submitted']) {
                $this->rememberRepeatInvitation($request, $survey, $invitation);
                $data['redirect'] = route('surveys.thanks', ['slug' => $survey->slug]);
            }
        }

        return response()->json(['csrf_token' => $request->session()->token(), ...$data]);
    }

    public function store(Request $request, string $slug, ?string $token = null): Response|JsonResponse|RedirectResponse
    {
        $survey = $this->publicSurvey($slug);
        $invitation = $this->invitation($survey, $token);
        $session = $request->session();
        $submissionKey = 'survey_submission_ids.'.$survey->id;
        $expected = $session->get($submissionKey);
        $draftScope = SurveySubmissionResumeToken::draftScope($survey, $invitation);
        $scopeMatches = ! $session->has('survey_submission_scopes.'.$survey->id)
            || $session->get('survey_submission_scopes.'.$survey->id) === $draftScope;

        try {
            $provided = $request->input('submission_id');
            if (! $scopeMatches || ! is_string($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
                throw ValidationException::withMessages(['submission_id' => 'Lūdzu, atveriet aptauju vēlreiz un mēģiniet vēlreiz.']);
            }

            app(SurveySubmissionService::class)->submit($survey, $request->only(['submission_id', 'form_definition', 'answers', 'other_answers', 'role', 'anonymous', 'respondent', 'respondent_name', 'respondent_role']), $invitation);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $survey->refresh();

            if (! $scopeMatches || ! is_string($expected) || ! Str::isUuid($expected)) {
                $expected = (string) Str::uuid();
                $session->put($submissionKey, $expected);
            }
            $session->put('survey_submission_scopes.'.$survey->id, $draftScope);

            return response()->view('surveys.show', [
                'survey' => $survey,
                'invitation' => $invitation,
                'submissionId' => $expected,
                'submissionResumeToken' => SurveySubmissionResumeToken::issue($survey, $invitation, $expected),
                'draftScope' => $draftScope,
                'submitted' => (bool) $session->get('survey_submitted.'.$survey->id, false),
                'oldValues' => $request->only(['answers', 'other_answers', 'role', 'anonymous', ...(! in_array($request->input('anonymous'), [false, 0, '0'], true) ? [] : ['respondent', 'respondent_name', 'respondent_role'])]),
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($exception->errors())),
            ], 422);
        }

        $session->put('survey_submitted.'.$survey->id, true);
        $this->rememberRepeatInvitation($request, $survey, $invitation);
        $redirect = route('surveys.thanks', ['slug' => $survey->slug]);

        return $request->expectsJson() ? response()->json(['redirect' => $redirect]) : redirect()->to($redirect);
    }

    public function restart(Request $request, string $slug, ?string $token = null): JsonResponse|RedirectResponse
    {
        $survey = $this->publicSurvey($slug);
        $invitation = $this->invitation($survey, $token);
        abort_unless($this->canRepeat($survey, $invitation), 422, 'Šī aptauja šobrīd nepieņem atbildes.');

        $session = $request->session();
        $session->forget('survey_submitted.'.$survey->id);
        $session->put('survey_submission_ids.'.$survey->id, (string) Str::uuid());
        $session->put('survey_submission_scopes.'.$survey->id, SurveySubmissionResumeToken::draftScope($survey, $invitation));
        $redirect = $invitation === null
            ? route('surveys.show', ['slug' => $survey->slug])
            : route('surveys.family', ['slug' => $survey->slug, 'token' => $token]);

        return $request->expectsJson() ? response()->json(['redirect' => $redirect]) : redirect()->to($redirect);
    }

    public function thanks(Request $request, string $slug): View
    {
        $survey = $this->publicSurvey($slug);
        $invitationId = $request->session()->get('survey_repeat_invitations.'.$survey->id);
        $invitation = is_int($invitationId)
            ? FamilyInvitation::query()->forSchool((int) $survey->school_id)->where('survey_id', $survey->id)->whereKey($invitationId)->first()
            : null;

        return view('surveys.thanks', [
            'survey' => $survey,
            'invitation' => $invitation,
            'draftScope' => SurveySubmissionResumeToken::draftScope($survey, $invitation),
            'canRepeat' => $this->canRepeat($survey, $invitation),
        ]);
    }

    private function canRepeat(Survey $survey, ?FamilyInvitation $invitation): bool
    {
        return $survey->isAcceptingResponses()
            && ($invitation === null || $invitation->participations()->whereIn('role', ['mother', 'father', 'guardian'])->count() < 3);
    }

    private function rememberRepeatInvitation(Request $request, Survey $survey, ?FamilyInvitation $invitation): void
    {
        $key = 'survey_repeat_invitations.'.$survey->id;
        if ($invitation === null) {
            $request->session()->forget($key);
        } else {
            $request->session()->put($key, $invitation->id);
        }
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
