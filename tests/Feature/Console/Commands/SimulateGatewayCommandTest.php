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

it('fails for an invoice the institution does not have', function () {
    $institution = Institution::factory()->create();
    Invoice::factory()->create(['invoice_number' => 'INV-2026-0001']);
    Http::fake();

    $this->artisan('gateway:simulate', ['scenario' => 'success', 'institution' => $institution->code, 'invoice' => 'INV-2026-0001'])
        ->assertFailed();

    Http::assertNothingSent();
});
