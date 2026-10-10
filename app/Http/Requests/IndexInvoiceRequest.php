<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Access is enforced by the `invoices:read` token ability on the route; the
 * institution scope limits the list to the caller's own institution.
 */
class IndexInvoiceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:32'],
            'semester' => ['sometimes', 'string', 'regex:/^SM-\d{4}-[1-3]$/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'semester.regex' => 'The semester must look like SM-2026-1: the SM prefix, a year, and a term number from 1 to 3.',
        ];
    }
}
