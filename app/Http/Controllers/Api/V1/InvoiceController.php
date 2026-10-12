<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Invoices
 *
 * @authenticated
 */
class InvoiceController extends Controller
{
    /**
     * List a student's invoices
     *
     * Requires invoices:read. Returns only the caller's institution, newest semester
     * first, with history ordered by occurred_at then id. An empty match returns data: [].
     * The explicit institution filter is the second layer behind the global scope.
     *
     * @queryParam student_number string required Student number, up to 32 characters. Example: 20260001
     * @queryParam semester string Optional semester in YYYY1 or YYYY2 format. Example: 20261
     *
     * @response 200 {"data":[{"invoice_number":"INV-2026-0001","student_number":"20260001","semester":"20261","amount":1500000,"status":"unpaid","expires_at":"2027-01-01T00:00:00+00:00","created_at":"2026-10-11T00:00:00+00:00","transactions":[]}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"Invalid ability provided."}
     * @response 422 {"message":"The student number field is required.","errors":{"student_number":["The student number field is required."]}}
     */
    public function index(IndexInvoiceRequest $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->where('institution_id', $request->user()->institution_id)
            ->where('student_number', $request->validated('student_number'))
            ->when($request->validated('semester'), fn ($query, string $semester) => $query->where('semester', $semester))
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->with('transactions')
            ->get();

        return InvoiceResource::collection($invoices);
    }

    /**
     * Issue an invoice
     *
     * Requires invoices:write. Institution ownership comes from the staff token;
     * a body institution_id is ignored. Duplicate invoice numbers in the same
     * institution return 422. Identifiers must have no whitespace
     * at their boundaries or control characters. The initial status is unpaid.
     *
     * @bodyParam invoice_number string required Unique number within the institution, up to 64 characters. Example: INV-2026-0001
     * @bodyParam student_number string required Student number, up to 32 characters. Example: 20260001
     * @bodyParam semester string required Semester in YYYY1 or YYYY2 format. Example: 20261
     * @bodyParam amount integer required Whole rupiah from 1 to 1000000000000; decimal and scientific notation are rejected. A canonical digit string is also accepted. Example: 1500000
     * @bodyParam expires_at string required Future date/time. Example: 2027-01-01T00:00:00+00:00
     *
     * @response 201 {"data":{"invoice_number":"INV-2026-0001","student_number":"20260001","semester":"20261","amount":1500000,"status":"unpaid","expires_at":"2027-01-01T00:00:00+00:00","created_at":"2026-10-11T00:00:00+00:00"}}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"Invalid ability provided."}
     * @response 422 {"message":"The invoice number has already been taken.","errors":{"invoice_number":["The invoice number has already been taken."]}}
     */
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): InvoiceResource
    {
        return InvoiceResource::make($createInvoice->handle($request->validated()));
    }

    /**
     * Show an invoice and its history
     *
     * Requires invoices:read. A missing invoice or another institution's invoice
     * returns 404. History is append-only, ordered by occurred_at then id, and
     * includes annotations derived from the ledger rather than stored edits.
     *
     * @urlParam invoice_number string required Invoice number, not the internal UUID. Example: INV-2026-0001
     *
     * @response 200 {"data":{"invoice_number":"INV-2026-0001","student_number":"20260001","semester":"20261","amount":1500000,"status":"paid","expires_at":"2027-01-01T00:00:00+00:00","created_at":"2026-10-11T00:00:00+00:00","transactions":[{"kind":"payment","annotation":null,"amount":1500000,"occurred_at":"2026-10-11T01:00:00+00:00","gateway_payment_id":"py-example","source":"webhook","idempotency_key":"payment.capture:py-example","recorded_at":"2026-10-11T01:00:01+00:00"}]}}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"Invalid ability provided."}
     * @response 404 {"message":"No query results for model [App\\Models\\Invoice] INV-2026-0001"}
     *
     * @responseField data.status string unpaid, paid, expired or refunded, derived from ledger facts.
     * @responseField data.transactions object[] Ordered ledger history. Informational events have a null amount.
     * @responseField data.transactions[].annotation string|null amount_mismatch, duplicate_payment, needs_review or null.
     */
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return InvoiceResource::make($invoice->load('transactions'));
    }
}
