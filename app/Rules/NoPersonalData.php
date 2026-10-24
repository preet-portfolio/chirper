<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A best-effort guard for a public board: reject text that looks like an email
 * address or a phone/card number (9+ digits, allowing spaces, dots, dashes,
 * brackets and a leading +). It backs up the disclaimer; it can't catch everything.
 */
class NoPersonalData implements ValidationRule
{
    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i';

    private const LONG_NUMBER = '/\+?\d(?:[\s().-]*\d){8,}/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (preg_match(self::EMAIL, $value) || preg_match(self::LONG_NUMBER, $value))) {
            $fail("Please don't post personal data like emails or phone numbers. This board is public.");
        }
    }
}
