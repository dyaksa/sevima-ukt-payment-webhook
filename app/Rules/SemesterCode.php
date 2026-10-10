<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts a semester code such as SM-2026-1. The fixed width (four-digit year,
 * one-digit term) also makes codes sort chronologically as plain strings.
 */
class SemesterCode implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^SM-\d{4}-[1-3]$/', $value) !== 1) {
            $fail('The :attribute must look like SM-2026-1: the SM prefix, a year, and a term number from 1 to 3.');
        }
    }
}
