<?php

namespace App\Http\Requests;

use App\Models\Scopes\InstitutionScope;
use App\Rules\Identifier;
use App\Rules\IntegerAmount;
use App\Rules\SemesterCode;
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
                'bail',
                'required',
                new Identifier,
                'max:64',
                Rule::unique('invoices')->where('institution_id', InstitutionScope::currentInstitutionId()),
            ],
            'student_number' => ['required', new Identifier, 'max:32'],
            'semester' => ['required', new SemesterCode],
            'amount' => ['required', new IntegerAmount],
            'expires_at' => ['required', 'date', 'after:now'],
        ];
    }
}
