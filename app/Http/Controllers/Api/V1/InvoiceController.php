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

class InvoiceController extends Controller
{
    /**
     * List one student's invoices in the caller's institution, newest semester
     * first, each with its transaction history.
     */
    public function index(IndexInvoiceRequest $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->where('student_number', $request->validated('student_number'))
            ->when($request->validated('semester'), fn ($query, string $semester) => $query->where('semester', $semester))
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->with('transactions')
            ->get();

        return InvoiceResource::collection($invoices);
    }

    /**
     * Issue an invoice for the caller's institution.
     */
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): InvoiceResource
    {
        return InvoiceResource::make($createInvoice->handle($request->validated()));
    }

    /**
     * Show one of the caller's institution's invoices by its number, with its
     * transaction history.
     */
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return InvoiceResource::make($invoice->load('transactions'));
    }
}
