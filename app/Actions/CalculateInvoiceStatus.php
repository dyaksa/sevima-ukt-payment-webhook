<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionKind;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class CalculateInvoiceStatus
{
    /**
     * Derive an invoice's status from its amount and its ledger alone. Pure:
     * no queries and no side effects, so the caller decides when to store it.
     *
     * @param  Collection<int, Transaction>  $transactions  ordered by event time, then id
     */
    public function handle(Invoice $invoice, Collection $transactions): InvoiceStatus
    {
        $hasValidPayment = $transactions->contains(
            fn (Transaction $transaction): bool => $transaction->kind === TransactionKind::Payment
                && $transaction->amount === $invoice->amount,
        );

        return $hasValidPayment ? InvoiceStatus::Paid : InvoiceStatus::Unpaid;
    }
}
