<?php

use App\Actions\CalculateInvoiceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('derives the status from the invoice amount and its ledger', function (Closure $ledger, InvoiceStatus $expected) {
    $invoice = Invoice::factory()->make(['amount' => 4_500_000]);

    expect((new CalculateInvoiceStatus)->handle($invoice, collect($ledger())))->toBe($expected);
})->with([
    'no transactions' => [fn () => [], InvoiceStatus::Unpaid],
    'a capture of the exact amount' => [
        fn () => [Transaction::factory()->make(['amount' => 4_500_000])],
        InvoiceStatus::Paid,
    ],
    'an expiry' => [
        fn () => [Transaction::factory()->expiry()->make()],
        InvoiceStatus::Expired,
    ],
    'an expiry after a capture of the exact amount' => [
        fn () => [Transaction::factory()->make(['amount' => 4_500_000]), Transaction::factory()->expiry()->make()],
        InvoiceStatus::Paid,
    ],
]);
