<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Services\QuestionContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuestionContactController extends Controller
{
    public function show(Request $request, string $slug, string $question): View
    {
        [$survey, $definition] = $this->resolve($slug, $question);

        return view('surveys.question-contact', [
            'survey' => $survey,
            'question' => $definition,
            'contactId' => (string) Str::uuid(),
            'oldValues' => [],
        ]);
    }

    public function store(Request $request, string $slug, string $question): Response|JsonResponse|RedirectResponse
    {
        [$survey, $definition] = $this->resolve($slug, $question);
        $payload = $request->only(['contact_id', 'consent', 'answer', 'fields']);

        try {
            app(QuestionContactService::class)->submit($survey, $question, $payload);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $provided = $payload['contact_id'] ?? null;

            return response()->view('surveys.question-contact', [
                'survey' => $survey,
                'question' => $definition,
                'contactId' => is_string($provided) && Str::isUuid($provided) && ! array_key_exists('contact_id', $exception->errors()) ? $provided : (string) Str::uuid(),
                'oldValues' => $payload,
                'errors' => (new ViewErrorBag)->put('default', new MessageBag($exception->errors())),
            ], 422);
        }

        return $request->expectsJson()
            ? response()->json(['saved' => true])
            : redirect()->route('question-contacts.thanks', ['slug' => $survey->slug, 'question' => $question]);
    }

    public function thanks(Request $request, string $slug, string $question): View
    {
        [$survey, $definition] = $this->resolve($slug, $question);

        return view('surveys.question-contact-thanks', ['survey' => $survey, 'question' => $definition]);
    }

    private function resolve(string $slug, string $questionKey): array
    {
        $survey = Survey::query()->where('slug', $slug)->whereIn('status', ['open', 'closed'])
            ->whereNotNull('published_at')->where('allow_identified_responses', false)->firstOrFail();
        $question = $survey->questionDefinition($questionKey, includeArchived: true);
        abort_unless(is_array($question) && in_array($question['type'] ?? null, ['single', 'multiple'], true) && is_array($question['contact'] ?? null), 404);

        return [$survey, $question];
    }
}
