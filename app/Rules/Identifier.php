<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts an identifier that PostgreSQL will match and store as sent: a string
 * without control characters. The pdo_pgsql driver cuts a bound value at the
 * first NUL byte, so "INV-1\0x" would otherwise match or be stored as "INV-1".
 */
class Identifier implements ValidationRule
{
    /**
     * Pattern for a value free of ASCII control characters.
     */
    public const string PATTERN = '[^\x00-\x1F\x7F]+';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^'.self::PATTERN.'$/D', $value) !== 1) {
            $fail('The :attribute must be text without control characters.');
        }
    }
}
