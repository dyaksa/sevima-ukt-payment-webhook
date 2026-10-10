<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    /**
     * Issue an invoice for the caller's institution.
     */
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): InvoiceResource
    {
        return InvoiceResource::make($createInvoice->handle($request->validated()));
    }

    /**
     * Show one of the caller's institution's invoices by its number.
     */
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return InvoiceResource::make($invoice);
    }
}
