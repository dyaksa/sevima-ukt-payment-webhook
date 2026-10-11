<?php

use App\Models\Institution;
use App\Models\Invoice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends a capture of the invoice amount signed with the institution secret', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', ['scenario' => 'success', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->expectsOutputToContain('200')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === route('v1.webhooks.store')
        && $request->header('X-Institution-Code') === [$institution->code]
        && $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]
        && $request['event'] === 'payment.capture'
        && $request['data']['reference_id'] === 'INV-2026-0001'
        && $request['data']['currency'] === 'IDR'
        && $request['data']['captures'][0]['capture_amount'] === 4_500_000);
});

it('sends a capture whose signature does not match in the bad-signature scenario', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::response(['message' => 'Invalid signature.'], 401)]);

    $this->artisan('gateway:simulate', ['scenario' => 'bad-signature', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->expectsOutputToContain('401')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->header('X-Signature') !== [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]);
});

it('sends a signed payment request expiry in the expiry scenario', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', ['scenario' => 'expiry', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->expectsOutputToContain('200')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]
        && $request['event'] === 'payment_request.expiry'
        && $request['data']['reference_id'] === 'INV-2026-0001'
        && is_string($request['data']['payment_request_id']));
});

it('sends the same signed capture six times in the retry scenario', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::sequence()
        ->push(['outcome' => 'accepted'])
        ->whenEmpty(Http::response(['outcome' => 'duplicate']))]);

    $this->artisan('gateway:simulate', ['scenario' => 'retry', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->expectsOutputToContain('accepted')
        ->expectsOutputToContain('duplicate')
        ->assertSuccessful();

    Http::assertSentCount(6);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request->body())->unique())->toHaveCount(1)
        ->and($sent->first()->header('X-Signature'))->toBe([hash_hmac('sha256', $sent->first()->body(), $institution->hmac_secret)])
        ->and($sent->first()['event'])->toBe('payment.capture');
});

it('sends an incorrect whole-rupiah capture at the chosen event time', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'wrong-amount',
        'institution' => $institution->code,
        'invoice' => 'INV-2026-0001',
        '--occurred-at' => '2026-10-10T08:00:00.123456Z',
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['data']['captures'][0]['capture_amount'] === 4_499_999
        && $request['data']['updated'] === '2026-10-10T08:00:00.123456Z'
        && $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]);
});

it('delivers an expiry after a capture while preserving the earlier expiry event time', function () {
    $this->travelTo('2026-10-10T10:00:00Z');
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'expiry-vs-success',
        'institution' => $institution->code,
        'invoice' => 'INV-2026-0001',
        '--occurred-at' => '2026-10-10T09:00:00Z',
    ])->assertSuccessful();

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request['event'])->all())->toBe(['payment.capture', 'payment_request.expiry'])
        ->and($sent->map(fn (Request $request) => $request['data']['updated'])->all())->toBe([
            '2026-10-10T09:00:00.000000Z',
            '2026-10-10T08:59:59.000000Z',
        ]);
    expect($sent->every(fn (Request $request): bool => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]))->toBeTrue();
});

it('uses a positive mismatched amount when the invoice is one rupiah', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 1]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', ['scenario' => 'wrong-amount', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['data']['captures'][0]['capture_amount'] === 2);
});

it('delivers authorization then capture for the same payment at distinct event times', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'authorization-then-success',
        'institution' => $institution->code,
        'invoice' => 'INV-2026-0001',
        '--occurred-at' => '2026-10-10T09:00:00Z',
    ])->assertSuccessful();

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request['event'])->all())->toBe(['payment.authorization', 'payment.capture'])
        ->and($sent->map(fn (Request $request) => $request['data']['payment_id'])->unique())->toHaveCount(1)
        ->and($sent->map(fn (Request $request) => $request['data']['updated'])->all())->toBe([
            '2026-10-10T08:59:59.000000Z',
            '2026-10-10T09:00:00.000000Z',
        ]);
    expect($sent->first()['data'])->not->toHaveKey('captures');
    expect($sent->every(fn (Request $request): bool => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]))->toBeTrue();
});

it('sends a signed failed payment without capture money', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', ['scenario' => 'failure', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['event'] === 'payment.failure'
        && $request['data']['status'] === 'FAILED'
        && ! array_key_exists('captures', $request['data'])
        && $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]);
});

it('delivers a payment then its signed refund without an invoice reference', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'refund', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001',
        '--occurred-at' => '2026-10-10T09:00:00Z',
    ])->assertSuccessful();

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request['event'])->all())->toBe(['payment.capture', 'refund.succeeded'])
        ->and($sent->map(fn (Request $request) => $request['data']['payment_id'])->unique())->toHaveCount(1)
        ->and($sent->last()['data']['amount'])->toBe(4_500_000)
        ->and($sent->last()['data']['updated'])->toBe('2026-10-10T09:00:00.000000Z')
        ->and($sent->last()['data'])->toHaveKey('id')->not->toHaveKey('reference_id');
    expect($sent->every(fn (Request $request): bool => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]))->toBeTrue();
});

it('delivers a failed refund after its capture without reporting a successful refund', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', ['scenario' => 'refund-failed', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->assertSuccessful();

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request['event'])->all())->toBe(['payment.capture', 'refund.failed'])
        ->and($sent->map(fn (Request $request) => $request['data']['payment_id'])->unique())->toHaveCount(1)
        ->and($sent->last()['data']['status'])->toBe('FAILED')
        ->and($sent->last()['data']['failure_code'])->toBe('REJECTED');
    expect($sent->every(fn (Request $request): bool => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]))->toBeTrue();
});

it('delivers a refund before its earlier payment for later reconciliation', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::sequence()->push(['outcome' => 'unmatched'], 202)->push(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'refund-before-payment', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001',
        '--occurred-at' => '2026-10-10T09:00:00Z',
    ])->expectsOutputToContain('202')->expectsOutputToContain('200')->assertSuccessful();

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent->map(fn (Request $request) => $request['event'])->all())->toBe(['refund.succeeded', 'payment.capture'])
        ->and($sent->map(fn (Request $request) => $request['data']['payment_id'])->unique())->toHaveCount(1)
        ->and($sent->map(fn (Request $request) => $request['data']['updated'])->all())->toBe([
            '2026-10-10T09:00:00.000000Z',
            '2026-10-10T08:59:59.000000Z',
        ]);
    expect($sent->every(fn (Request $request): bool => $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), $institution->hmac_secret)]))->toBeTrue();
});

it('rejects an explicitly empty event time instead of delivering with the current time', function (string $occurredAt) {
    $institution = Institution::factory()->create();
    Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'success',
        'institution' => $institution->code,
        'invoice' => 'INV-2026-0001',
        '--occurred-at' => $occurredAt,
    ])->expectsOutputToContain('The event time must be an ISO 8601 timestamp with an offset.')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['empty' => '', 'whitespace' => ' ']);

it('rejects a malformed event time without delivering a notification', function () {
    Http::fake([route('v1.webhooks.store') => Http::response(['outcome' => 'accepted'])]);

    $this->artisan('gateway:simulate', [
        'scenario' => 'success',
        'institution' => 'UNIV-A',
        'invoice' => 'INV-2026-0001',
        '--occurred-at' => 'not-a-date',
    ])->expectsOutputToContain('The event time must be an ISO 8601 timestamp with an offset.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails for an invoice the institution does not have', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake();

    $this->artisan('gateway:simulate', ['scenario' => 'success', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->assertFailed();

    Http::assertNothingSent();
});
