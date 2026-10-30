<?php

namespace App\Support;

class ContactField
{
    public static function type(array $field): string
    {
        return match ($field['key']) {
            'name' => 'text',
            'email' => 'email',
            'phone' => 'tel',
            'role' => 'role',
            default => $field['type'],
        };
    }
}
