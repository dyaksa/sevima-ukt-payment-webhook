<?php

use App\Actions\CalculateInvoiceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('derives the status from the invoice amount and its ledger', function (array $payments, InvoiceStatus $expected) {
    $invoice = Invoice::factory()->make(['amount' => 4_500_000]);
    $transactions = collect($payments)->map(fn (int $amount) => Transaction::factory()->make(['amount' => $amount]));

    expect((new CalculateInvoiceStatus)->handle($invoice, $transactions))->toBe($expected);
})->with([
    'no transactions' => [[], InvoiceStatus::Unpaid],
    'a capture of the exact amount' => [[4_500_000], InvoiceStatus::Paid],
]);
