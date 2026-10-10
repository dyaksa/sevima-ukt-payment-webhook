<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreateInvoice
{
    /**
     * Issue an unpaid invoice for the current institution.
     *
     * Validation already checks the invoice number, but two concurrent requests
     * can both pass it; the database unique constraint decides, and the loser
     * gets the same validation error instead of a server error.
     *
     * @param  array{invoice_number: string, student_number: string, semester: string, amount: int|string, expires_at: string}  $attributes
     *
     * @throws ValidationException
     */
    public function handle(array $attributes): Invoice
    {
        try {
            return Invoice::create([
                'invoice_number' => $attributes['invoice_number'],
                'student_number' => $attributes['student_number'],
                'semester' => $attributes['semester'],
                'amount' => (int) $attributes['amount'],
                'status' => InvoiceStatus::Unpaid,
                'expires_at' => $attributes['expires_at'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invoice_number' => __('validation.unique', ['attribute' => 'invoice number']),
            ]);
        }
    }
}
