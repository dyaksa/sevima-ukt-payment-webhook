<?php

use App\Actions\CalculateInvoiceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionKind;
use App\Models\Invoice;
use App\Models\Transaction;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('derives the status from the invoice amount and its ledger', function (array $ledger, InvoiceStatus $expected) {
    $invoice = Invoice::factory()->make(['amount' => 4_500_000]);
    $transactions = collect($ledger)->map(fn (array $row) => Transaction::factory()->make(['kind' => $row[0], 'amount' => $row[1]]));

    expect((new CalculateInvoiceStatus)->handle($invoice, $transactions))->toBe($expected);
})->with([
    'no transactions' => [[], InvoiceStatus::Unpaid],
    'a capture of the exact amount' => [[[TransactionKind::Payment, 4_500_000]], InvoiceStatus::Paid],
    'an expiry' => [[[TransactionKind::Expiry, null]], InvoiceStatus::Expired],
    'an expiry after a capture of the exact amount' => [[[TransactionKind::Payment, 4_500_000], [TransactionKind::Expiry, null]], InvoiceStatus::Paid],
]);
