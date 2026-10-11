<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionAnnotation;
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

        if ($hasValidPayment) {
            $remaining = $invoice->amount;

            foreach ($transactions as $transaction) {
                if ($transaction->kind === TransactionKind::Refund) {
                    // Cap at the balance so cumulative refunds never overflow integer rupiah.
                    $remaining -= min($remaining, $transaction->amount);

                    if ($remaining === 0) {
                        return InvoiceStatus::Refunded;
                    }
                }
            }

            return InvoiceStatus::Paid;
        }

        $hasExpiry = $transactions->contains(
            fn (Transaction $transaction): bool => $transaction->kind === TransactionKind::Expiry,
        );

        return $hasExpiry ? InvoiceStatus::Expired : InvoiceStatus::Unpaid;
    }

    /**
     * Derive annotations from ledger facts without changing or persisting them.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, TransactionAnnotation>
     */
    public function annotations(Invoice $invoice, Collection $transactions): array
    {
        $annotations = [];
        $hasValidPayment = false;

        $ordered = $transactions->sort(fn (Transaction $left, Transaction $right): int => $left->occurred_at <=> $right->occurred_at ?: strcmp($left->id, $right->id));

        foreach ($ordered as $transaction) {
            if ($transaction->kind === TransactionKind::Refund && $transaction->amount < $invoice->amount) {
                $annotations[$transaction->id] = TransactionAnnotation::NeedsReview;
            }

            if ($transaction->kind !== TransactionKind::Payment) {
                continue;
            }

            if ($transaction->amount !== $invoice->amount) {
                $annotations[$transaction->id] = TransactionAnnotation::AmountMismatch;
            } elseif ($hasValidPayment) {
                $annotations[$transaction->id] = TransactionAnnotation::DuplicatePayment;
            } else {
                $hasValidPayment = true;
            }
        }

        return $annotations;
    }
}
