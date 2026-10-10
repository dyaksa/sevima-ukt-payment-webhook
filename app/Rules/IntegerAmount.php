<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts a whole rupiah amount given as a JSON integer or a digit string.
 * Floats are rejected even when they hold a whole value (1500000.0), and so
 * are fractions and scientific notation, so no float ever reaches money code.
 */
class IntegerAmount implements ValidationRule
{
    /**
     * The largest accepted amount in rupiah (one trillion).
     */
    public const int MAXIMUM = 1_000_000_000_000;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^[1-9][0-9]{0,12}$/', $value) === 1 => (int) $value,
            default => null,
        };

        if ($amount === null || $amount < 1 || $amount > self::MAXIMUM) {
            $fail('The :attribute must be a whole rupiah amount from 1 to '.self::MAXIMUM.'.');
        }
    }
}
