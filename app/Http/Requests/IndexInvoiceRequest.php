<?php

namespace App\Http\Requests;

use App\Rules\SemesterCode;
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
            'semester' => ['nullable', new SemesterCode],
        ];
    }
}
