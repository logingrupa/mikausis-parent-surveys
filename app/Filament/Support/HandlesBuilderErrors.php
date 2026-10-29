<?php

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait HandlesBuilderErrors
{
    protected function withBuilderValidation(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Pārbaudiet aptaujas iestatījumus')
                ->body(e(implode(' ', array_unique($exception->validator->errors()->all()))))
                ->danger()->persistent()->send();

            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $root = explode('.', $field)[0];
                if (in_array($root, ['title', 'description', 'sections', 'questions', 'allow_identified_responses', 'respondent_fields'], true)) {
                    $field = 'builder.'.$field;
                }
                $errors[str_starts_with($field, 'data.') ? $field : 'data.'.$field] = $messages;
            }
            $this->dispatch('survey-editor-errors', errors: $errors);

            throw ValidationException::withMessages($errors);
        }
    }
}
