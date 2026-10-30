<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ContactPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = false;
        if (is_string($value) && preg_match('/\A\+?[0-9 ()-]+\z/', $value)) {
            $number = str_replace([' ', '(', ')', '-'], '', $value);
            if (str_starts_with($number, '00')) {
                $number = '+'.substr($number, 2);
            }

            if (str_starts_with($number, '+371')) {
                $valid = (bool) preg_match('/\A\+371[26][0-9]{7}\z/', $number);
            } elseif (str_starts_with($number, '+')) {
                $valid = (bool) preg_match('/\A\+[1-9][0-9]{6,14}\z/', $number);
            } else {
                $valid = (bool) preg_match('/\A[26][0-9]{7}\z/', $number);
            }
        }

        if (! $valid) {
            $fail('Lūdzu, pārbaudiet tālruņa numuru. Piemērs: +371 20000000.');
        }
    }
}
