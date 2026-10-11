<?php

use App\Actions\CalculateInvoiceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionAnnotation;
use App\Enums\TransactionKind;
use App\Models\Invoice;
use App\Models\Transaction;
use Tests\TestCase;

pest()->extend(TestCase::class);

/**
 * @param  array{0: string, 1: ?int, 2: string, 3?: TransactionKind}  $fact
 */
function ledgerTransaction(array $fact): Transaction
{
    return Transaction::factory()->withoutParents()->make([
        'id' => $fact[0],
        'kind' => $fact[3] ?? TransactionKind::Payment,
        'amount' => $fact[1],
    ])->setDateFormat('Y-m-d H:i:s.uP')->forceFill(['occurred_at' => $fact[2]]);
}

it('derives the status from the invoice amount and its ledger', function (Closure $ledger, InvoiceStatus $expected) {
    $invoice = Invoice::factory()->withoutParents()->make(['amount' => 4_500_000]);

    expect((new CalculateInvoiceStatus)->handle($invoice, collect($ledger())))->toBe($expected);
})->with([
    'no transactions' => [fn () => [], InvoiceStatus::Unpaid],
    'a capture of the exact amount' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_500_000])],
        InvoiceStatus::Paid,
    ],
    'an expiry' => [
        fn () => [Transaction::factory()->withoutParents()->expiry()->make()],
        InvoiceStatus::Expired,
    ],
    'an expiry after a capture of the exact amount' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_500_000]), Transaction::factory()->withoutParents()->expiry()->make()],
        InvoiceStatus::Paid,
    ],
    'a capture received after expiry' => [
        fn () => [Transaction::factory()->withoutParents()->expiry()->make(), Transaction::factory()->withoutParents()->make(['amount' => 4_500_000])],
        InvoiceStatus::Paid,
    ],
    'an underpayment' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_000_000])],
        InvoiceStatus::Unpaid,
    ],
    'an overpayment' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 5_000_000])],
        InvoiceStatus::Unpaid,
    ],
    'two partial payments are not a valid full payment' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_000_000]), Transaction::factory()->withoutParents()->make(['amount' => 500_000])],
        InvoiceStatus::Unpaid,
    ],
    'an incorrect payment after expiry' => [
        fn () => [Transaction::factory()->withoutParents()->expiry()->make(), Transaction::factory()->withoutParents()->make(['amount' => 5_000_000])],
        InvoiceStatus::Expired,
    ],
    'an incorrect payment after a valid payment' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_500_000]), Transaction::factory()->withoutParents()->make(['amount' => 5_000_000])],
        InvoiceStatus::Paid,
    ],
    'two distinct valid payments' => [
        fn () => [Transaction::factory()->withoutParents()->make(['amount' => 4_500_000]), Transaction::factory()->withoutParents()->make(['amount' => 4_500_000])],
        InvoiceStatus::Paid,
    ],
]);

it('leaves ledger facts and collection order unchanged when deriving annotations', function () {
    $invoice = Invoice::factory()->withoutParents()->make(['amount' => 4_500_000]);
    $transactions = collect([
        ledgerTransaction(['p2', 4_500_000, '2026-10-10T09:00:00Z']),
        ledgerTransaction(['p1', 4_500_000, '2026-10-10T08:00:00Z']),
    ]);
    $facts = $transactions->map(fn (Transaction $transaction): array => $transaction->getAttributes())->all();

    (new CalculateInvoiceStatus)->annotations($invoice, $transactions);

    expect($transactions->map(fn (Transaction $transaction): array => $transaction->getAttributes())->all())->toBe($facts);
});

it('derives payment annotations in event order with ids breaking ties', function (array $facts, array $expected) {
    $invoice = Invoice::factory()->withoutParents()->make(['amount' => 4_500_000]);
    $transactions = collect($facts)->map(fn (array $fact): Transaction => ledgerTransaction($fact));

    expect((new CalculateInvoiceStatus)->annotations($invoice, $transactions))->toBe($expected);
})->with([
    'underpayment' => [[['p1', 4_000_000, '2026-10-10T08:00:00Z']], ['p1' => TransactionAnnotation::AmountMismatch]],
    'overpayment' => [[['p1', 5_000_000, '2026-10-10T08:00:00Z']], ['p1' => TransactionAnnotation::AmountMismatch]],
    'exact payment' => [[['p1', 4_500_000, '2026-10-10T08:00:00Z']], []],
    'wrong payment does not take the valid payment slot' => [[
        ['p1', 4_000_000, '2026-10-10T08:00:00Z'],
        ['p2', 4_500_000, '2026-10-10T09:00:00Z'],
    ], ['p1' => TransactionAnnotation::AmountMismatch]],
    'later valid payment' => [[
        ['p1', 4_500_000, '2026-10-10T08:00:00Z'],
        ['p2', 4_500_000, '2026-10-10T09:00:00Z'],
    ], ['p2' => TransactionAnnotation::DuplicatePayment]],
    'reverse arrival order' => [[
        ['p2', 4_500_000, '2026-10-10T09:00:00Z'],
        ['p1', 4_500_000, '2026-10-10T08:00:00Z'],
    ], ['p2' => TransactionAnnotation::DuplicatePayment]],
    'microseconds break arrival order' => [[
        ['p1', 4_500_000, '2026-10-10T08:00:00.000002Z'],
        ['p2', 4_500_000, '2026-10-10T08:00:00.000001Z'],
    ], ['p1' => TransactionAnnotation::DuplicatePayment]],
    'id breaks equal event times' => [[
        ['p2', 4_500_000, '2026-10-10T08:00:00Z'],
        ['p1', 4_500_000, '2026-10-10T08:00:00Z'],
    ], ['p2' => TransactionAnnotation::DuplicatePayment]],
    'expiry is not a payment' => [[
        ['e1', null, '2026-10-10T08:00:00Z', TransactionKind::Expiry],
        ['p1', 4_500_000, '2026-10-10T09:00:00Z'],
    ], []],
]);
