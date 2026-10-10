<?php

namespace App\Http\Requests;

use App\Models\Scopes\InstitutionScope;
use App\Rules\IntegerAmount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Access is enforced by the `invoices:write` token ability on the route; any
 * authenticated staff member with that ability may issue invoices for their
 * own institution.
 */
class StoreInvoiceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invoice_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('invoices')->where('institution_id', InstitutionScope::currentInstitutionId()),
            ],
            'student_number' => ['required', 'string', 'max:32'],
            'semester' => ['required', 'string', 'regex:/^SM-\d{4}-[1-3]$/'],
            'amount' => ['required', new IntegerAmount],
            'expires_at' => ['required', 'date', 'after:now'],
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
