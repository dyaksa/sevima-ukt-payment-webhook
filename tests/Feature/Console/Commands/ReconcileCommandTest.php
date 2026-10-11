<?php

use App\Enums\InvoiceStatus;
use App\Enums\TransactionSource;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Scopes\InstitutionScope;
use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

it('reports a missing webhook without applying it by default', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();

    $this->artisan('reconcile:run')
        ->expectsOutputToContain('missing_webhook | UNIV-A | INV-2026-0001')
        ->assertSuccessful();

    expect(Transaction::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    Http::assertNothingSent();
});

it('warns about a ledger row absent from the book without modifying it', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    Log::spy();
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'status' => InvoiceStatus::Paid]);
    $transaction = Transaction::factory()->for($invoice)->create(['institution_id' => $institution->id, 'amount' => $invoice->amount]);
    Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);
    $before = $transaction->fresh()->getRawOriginal();

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('missing_gateway_event | UNIV-A | INV-2026-0001')
        ->assertSuccessful();

    expect($transaction->fresh()->getRawOriginal())->toBe($before)
        ->and(Transaction::count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(WebhookLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('Reconciliation difference.', Mockery::on(fn (array $context): bool => $context['difference'] === 'missing_gateway_event'));
});

it('warns about different amounts for the same event without applying or rewriting it', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    Log::spy();
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000, 'status' => InvoiceStatus::Paid]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();
    $entry = json_decode(trim(Storage::disk('local')->get('gateway-book.jsonl')), true, flags: JSON_THROW_ON_ERROR);
    $paymentId = $entry['payload']['data']['payment_id'];
    $transaction = Transaction::factory()->for($invoice)->create([
        'institution_id' => $institution->id, 'idempotency_key' => 'payment.capture:'.$paymentId,
        'gateway_payment_id' => $paymentId, 'amount' => 4_499_999,
    ]);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('amount_mismatch | UNIV-A | INV-2026-0001')
        ->assertSuccessful();

    expect($transaction->fresh()->amount)->toBe(4_499_999)
        ->and(Transaction::count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(WebhookLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('Reconciliation difference.', Mockery::on(fn (array $context): bool => $context['difference'] === 'amount_mismatch'
        && $context['gateway_amount'] === 4_500_000 && $context['ledger_amount'] === 4_499_999));
});

it('uses an explicit hour lookback and excludes older missing events', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
        '--occurred-at' => '2026-10-11T03:59:59Z',
    ])->assertSuccessful();
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
        '--occurred-at' => '2026-10-11T04:00:00Z',
    ])->assertSuccessful();

    $this->artisan('reconcile:run', ['--hours' => '2', '--apply' => true])->assertSuccessful();

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::sole()->occurred_at->toIso8601String())->toBe('2026-10-11T04:00:00+00:00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('preserves institution isolation when invoice numbers and gateway resource ids repeat', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $first = Institution::factory()->create(['code' => 'UNIV-A']);
    $second = Institution::factory()->create(['code' => 'UNIV-B']);
    $firstInvoice = Invoice::factory()->for($first)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $secondInvoice = Invoice::factory()->for($second)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $first->code, 'invoice' => $firstInvoice->invoice_number,
    ])->assertSuccessful();
    $entry = json_decode(trim(Storage::disk('local')->get('gateway-book.jsonl')), true, flags: JSON_THROW_ON_ERROR);
    $paymentId = $entry['payload']['data']['payment_id'];
    Context::add(InstitutionScope::CONTEXT_KEY, $second->id);
    Transaction::factory()->for($secondInvoice)->create([
        'institution_id' => $second->id, 'idempotency_key' => 'payment.capture:'.$paymentId,
        'gateway_payment_id' => $paymentId, 'amount' => 4_500_000,
    ]);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('missing_webhook | UNIV-A | INV-2026-0001')
        ->expectsOutputToContain('missing_gateway_event | UNIV-B | INV-2026-0001')
        ->assertSuccessful();

    expect($firstInvoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($secondInvoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Context::get(InstitutionScope::CONTEXT_KEY))->toBe($second->id)
        ->and(Transaction::sole()->source)->toBe(TransactionSource::Webhook);
    Context::add(InstitutionScope::CONTEXT_KEY, $first->id);
    expect(Transaction::sole()->source)->toBe(TransactionSource::Reconciliation);
});

it('defaults to an inclusive 48 hour event window for both book and ledger', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    foreach (['2026-10-09T05:59:59Z', '2026-10-09T06:00:00Z', '2026-10-11T06:00:01Z'] as $time) {
        $this->artisan('gateway:simulate', [
            'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
            '--occurred-at' => $time,
        ])->assertSuccessful();
    }
    Transaction::factory()->for($invoice)->create([
        'institution_id' => $institution->id, 'occurred_at' => '2026-10-09T05:59:59Z',
    ]);

    $this->artisan('reconcile:run', ['--apply' => true])->assertSuccessful();

    expect(Transaction::count())->toBe(2)
        ->and(Transaction::where('source', TransactionSource::Reconciliation)->sole()->occurred_at->toIso8601String())->toBe('2026-10-09T06:00:00+00:00');
    $this->artisan('reconcile:run')->expectsOutputToContain('No differences.')->assertSuccessful();
});

it('rejects invalid lookback hours without applying any event', function (string $hours) {
    Storage::fake('local');
    $this->artisan('reconcile:run', ['--hours' => $hours, '--apply' => true])
        ->expectsOutputToContain('The lookback hours must be a positive integer within the supported date range.')
        ->assertFailed();
    $this->assertDatabaseCount('transactions', 0);
})->with(['zero' => '0', 'negative' => '-1', 'fraction' => '1.5', 'scientific' => '1e2', 'empty' => '', 'out of range' => (string) PHP_INT_MAX]);

it('rejects an invalid gateway book before applying any valid entries', function (string $amount) {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();
    $valid = Storage::disk('local')->get('gateway-book.jsonl');
    $entry = json_decode(trim($valid), true, flags: JSON_THROW_ON_ERROR);
    $entry['payload']['data']['captures'][0]['capture_amount'] = '__invalid_amount__';
    $invalid = str_replace('"__invalid_amount__"', $amount, json_encode($entry, JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('gateway-book.jsonl', $valid.$invalid."\n");

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('Reconciliation failed.')
        ->assertFailed();

    expect(Transaction::count())->toBe(0)
        ->and(WebhookLog::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with([
    'scientific string' => '"1e6"', 'fractional string' => '"1000000.5"',
    'scientific number' => '1e6', 'whole float' => '1000000.0', 'fractional number' => '1000000.5',
]);

it('reports a payment assigned to a different invoice without applying it', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);
    $gatewayInvoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-B', 'amount' => 4_500_000]);
    $ledgerInvoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-A', 'amount' => 4_500_000, 'status' => InvoiceStatus::Paid]);
    $this->artisan('gateway:simulate', ['scenario' => 'lost', 'institution' => $institution->code, 'invoice' => 'INV-B'])->assertSuccessful();
    $entry = json_decode(trim(Storage::disk('local')->get('gateway-book.jsonl')), true, flags: JSON_THROW_ON_ERROR);
    $paymentId = $entry['payload']['data']['payment_id'];
    Transaction::factory()->for($ledgerInvoice)->create([
        'institution_id' => $institution->id, 'idempotency_key' => 'payment.capture:'.$paymentId,
        'gateway_payment_id' => $paymentId, 'amount' => 4_500_000,
    ]);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('missing_webhook | UNIV-A | INV-B')
        ->expectsOutputToContain('missing_gateway_event | UNIV-A | INV-A')
        ->assertSuccessful();

    expect(Transaction::count())->toBe(1)
        ->and(WebhookLog::count())->toBe(0)
        ->and($gatewayInvoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($ledgerInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('fails on malformed book content before applying a preceding valid event', function (string $line) {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    $this->artisan('gateway:simulate', ['scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number])->assertSuccessful();
    Storage::disk('local')->append('gateway-book.jsonl', $line);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('Reconciliation failed.')
        ->assertFailed();

    expect(Transaction::count())->toBe(0)
        ->and(WebhookLog::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
})->with(['truncated JSON' => '{"institution_code":', 'missing envelope fields' => '{}']);

it('fails instead of treating an unreadable gateway book as empty', function () {
    Storage::fake('local');
    Storage::disk('local')->makeDirectory('gateway-book.jsonl');

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('Reconciliation failed.')
        ->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
});

it('registers automatic reconciliation hourly without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => str_contains($event->command ?? '', 'reconcile:run'));

    expect($event->command)->toContain('reconcile:run', '--apply')
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('includes the resolved invoice when reporting an unmatched refund after its payment arrives', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'refund-before-payment', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();
    $entries = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), preg_split('/\R/', trim(Storage::disk('local')->get('gateway-book.jsonl'))));
    $paymentId = $entries[1]['payload']['data']['payment_id'];
    Transaction::factory()->for($invoice)->create([
        'institution_id' => $institution->id, 'idempotency_key' => 'payment.capture:'.$paymentId,
        'gateway_payment_id' => $paymentId, 'amount' => 4_500_000,
    ]);

    $this->artisan('reconcile:run')
        ->expectsOutputToContain('missing_webhook | UNIV-A | INV-2026-0001 | refund.succeeded:')
        ->assertSuccessful();

    $this->artisan('reconcile:run', ['--apply' => true])->assertSuccessful();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and(Transaction::count())->toBe(2);
});

it('applies missing capture and refund in event time order rather than book order', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'refund-before-payment', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();

    $this->artisan('reconcile:run', ['--apply' => true])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and(Transaction::count())->toBe(2)
        ->and(Transaction::where('source', TransactionSource::Reconciliation)->count())->toBe(2)
        ->and(WebhookLog::where('outcome', 'unmatched')->count())->toBe(0);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('No differences.')
        ->assertSuccessful();
    expect(WebhookLog::count())->toBe(2);
});

it('applies a lost payment once through the ledger and audit processing path', function () {
    $this->travelTo('2026-10-11T06:00:00Z');
    Storage::fake('local');
    $institution = Institution::factory()->create();
    $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    $this->artisan('gateway:simulate', [
        'scenario' => 'lost', 'institution' => $institution->code, 'invoice' => $invoice->invoice_number,
    ])->assertSuccessful();
    $book = Storage::disk('local')->get('gateway-book.jsonl');

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('accepted')
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::sole()->source)->toBe(TransactionSource::Reconciliation)
        ->and(Transaction::sole()->amount)->toBe(4_500_000)
        ->and(WebhookLog::count())->toBe(1);

    $this->artisan('reconcile:run', ['--apply' => true])
        ->expectsOutputToContain('No differences.')
        ->assertSuccessful();

    expect(Transaction::count())->toBe(1)
        ->and(WebhookLog::count())->toBe(1)
        ->and(Storage::disk('local')->get('gateway-book.jsonl'))->toBe($book);
    Http::assertNothingSent();
});
