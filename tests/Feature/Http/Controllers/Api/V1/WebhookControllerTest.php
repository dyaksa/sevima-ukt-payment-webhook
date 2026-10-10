<?php

use App\Actions\CalculateInvoiceStatus;
use App\Enums\TokenAbility;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * A Xendit-shaped capture notification for an invoice.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function capturePayload(array $data = []): array
{
    return [
        'event' => 'payment.capture',
        'business_id' => 'UNIV-A',
        'created' => '2026-10-10T08:00:01.000Z',
        'data' => [
            'payment_id' => 'py-0001',
            'payment_request_id' => 'pr-0001',
            'reference_id' => 'INV-2026-0001',
            'currency' => 'IDR',
            'status' => 'SUCCEEDED',
            'captures' => [['capture_id' => 'cp-0001', 'capture_amount' => 4_500_000]],
            'updated' => '2026-10-10T08:00:00.000Z',
            ...$data,
        ],
    ];
}

/**
 * A Xendit-shaped payment request expiry notification for an invoice.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function expiryPayload(array $data = [], string $event = 'payment_request.expiry'): array
{
    return [
        'event' => $event,
        'business_id' => 'UNIV-A',
        'created' => '2026-10-10T09:00:01.000Z',
        'data' => [
            'payment_request_id' => 'pr-0001',
            'reference_id' => 'INV-2026-0001',
            'currency' => 'IDR',
            'request_amount' => 4_500_000,
            'status' => 'EXPIRED',
            'updated' => '2026-10-10T09:00:00.000Z',
            ...$data,
        ],
    ];
}

/**
 * Post a raw body to the webhook, signed with the given secret (by default the
 * institution's own), exactly as the gateway sends it.
 */
function sendWebhook(string $body, string $institutionCode, ?string $secret): TestResponse
{
    return test()->call('POST', route('v1.webhooks.store'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_INSTITUTION_CODE' => $institutionCode,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $secret ?? ''),
    ], content: $body);
}

function sendSignedWebhook(Institution $institution, array|string $payload): TestResponse
{
    $body = is_string($payload) ? $payload : json_encode($payload);

    return sendWebhook($body, $institution->code, $institution->hmac_secret);
}

it('marks the invoice paid on a capture of the exact amount and shows it in the history', function () {
    $user = User::factory()->create();
    Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

    sendSignedWebhook($user->institution, capturePayload())
        ->assertOk()
        ->assertJson(['outcome' => 'accepted']);

    Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
    $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.kind', 'payment')
        ->assertJsonPath('data.transactions.0.amount', 4_500_000)
        ->assertJsonPath('data.transactions.0.occurred_at', '2026-10-10T08:00:00+00:00')
        ->assertJsonPath('data.transactions.0.gateway_payment_id', 'py-0001')
        ->assertJsonPath('data.transactions.0.source', 'webhook');

    $this->assertDatabaseHas('webhook_logs', [
        'institution_id' => $user->institution_id,
        'institution_code' => $user->institution->code,
        'outcome' => 'accepted',
        'event' => 'payment.capture',
        'idempotency_key' => 'payment.capture:py-0001',
        'invoice_number' => 'INV-2026-0001',
    ]);
});

describe('signature', function () {
    it('rejects a body altered after signing with 401', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $signedBody = json_encode(capturePayload());
        $alteredBody = str_replace('INV-2026-0001', 'INV-2026-0002', $signedBody);

        test()->call('POST', route('v1.webhooks.store'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INSTITUTION_CODE' => $institution->code,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $signedBody, $institution->hmac_secret),
        ], content: $alteredBody)->assertUnauthorized();

        expect($invoice->fresh()->status->value)->toBe('unpaid');
        $this->assertDatabaseCount('transactions', 0);
    });

    it('rejects a body signed with another institution secret with 401', function () {
        $institution = Institution::factory()->create();
        $otherInstitution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendWebhook(json_encode(capturePayload()), $institution->code, $otherInstitution->hmac_secret)
            ->assertUnauthorized();

        $this->assertDatabaseCount('transactions', 0);
    });

    it('answers an unknown institution code exactly like a bad signature', function () {
        $institution = Institution::factory()->create();
        $body = json_encode(capturePayload());

        $badSignature = sendWebhook($body, $institution->code, 'not-the-secret');
        $unknownCode = sendWebhook($body, 'UNIV-UNKNOWN', 'not-the-secret');

        $badSignature->assertUnauthorized();
        expect($unknownCode->status())->toBe($badSignature->status())
            ->and($unknownCode->getContent())->toBe($badSignature->getContent());
    });

    it('logs every rejected request with the sent code and no institution when the code is unknown', function () {
        $institution = Institution::factory()->create();
        $body = json_encode(capturePayload());

        sendWebhook($body, $institution->code, 'not-the-secret')->assertUnauthorized();
        sendWebhook($body, 'UNIV-UNKNOWN', 'not-the-secret')->assertUnauthorized();

        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'institution_code' => $institution->code, 'outcome' => 'unauthorized', 'raw_body' => $body]);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => null, 'institution_code' => 'UNIV-UNKNOWN', 'outcome' => 'unauthorized']);
    });

    it('logs a rejected request whose body is not valid UTF-8', function () {
        sendWebhook("{\"event\":\"\xff\xfe\0\"}", 'UNIV-UNKNOWN', 'not-the-secret')->assertUnauthorized();

        $this->assertDatabaseHas('webhook_logs', ['institution_code' => 'UNIV-UNKNOWN', 'outcome' => 'unauthorized']);
    });
});

describe('payload', function () {
    it('rejects a capture amount that is not a whole rupiah integer with 422', function (string $amount) {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $body = str_replace('"capture_amount":4500000', '"capture_amount":'.$amount, json_encode(capturePayload()));

        sendSignedWebhook($institution, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.captures.0.capture_amount']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid', 'raw_body' => $body]);
    })->with([
        'fraction' => '4500000.5',
        'whole float' => '4500000.0',
        'scientific notation' => '4.5e6',
        'decimal string' => '"4500000.00"',
    ]);

    it('records the event time from data.updated in any ISO 8601 offset', function (string $updated, string $expected) {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($user->institution, capturePayload(['updated' => $updated]))->assertOk();

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertJsonPath('data.transactions.0.occurred_at', $expected);
    })->with([
        'Z with milliseconds' => ['2026-10-10T08:00:00.123Z', '2026-10-10T08:00:00+00:00'],
        'explicit UTC offset' => ['2026-10-10T08:00:00+00:00', '2026-10-10T08:00:00+00:00'],
        'WIB offset' => ['2026-10-10T15:00:00+07:00', '2026-10-10T08:00:00+00:00'],
    ]);

    it('rejects a currency other than IDR with 422', function () {
        $institution = Institution::factory()->create();

        sendSignedWebhook($institution, capturePayload(['currency' => 'USD']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.currency' => 'Only IDR is accepted.']);
    });

    it('rejects an unknown event name with 422', function () {
        $institution = Institution::factory()->create();

        sendSignedWebhook($institution, [...capturePayload(), 'event' => 'payment.teleported'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event']);
    });

    it('rejects an expiry without a payment request id with 422', function () {
        $institution = Institution::factory()->create();
        $payload = expiryPayload();
        unset($payload['data']['payment_request_id']);

        sendSignedWebhook($institution, $payload)->assertUnprocessable()->assertJsonValidationErrors('data.payment_request_id');
    });

    it('accepts the brief alias payment.success as a capture', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, [...capturePayload(), 'event' => 'payment.success'])->assertOk();

        expect($invoice->fresh()->status->value)->toBe('paid');
    });

    it('marks the invoice paid when the captures add up to its amount', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload(['captures' => [
            ['capture_id' => 'cp-0001', 'capture_amount' => 4_000_000],
            ['capture_id' => 'cp-0002', 'capture_amount' => '500000'],
        ]]))->assertOk();

        expect($invoice->fresh()->status->value)->toBe('paid');
    });
});

describe('processing', function () {
    it('cannot touch another institution invoice and answers 202 as unmatched', function () {
        $institution = Institution::factory()->create();
        $otherInvoice = Invoice::factory()->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload())
            ->assertAccepted()
            ->assertJson(['outcome' => 'unmatched']);

        expect($otherInvoice->fresh()->status->value)->toBe('unpaid');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'unmatched', 'invoice_number' => 'INV-2026-0001']);
    });

    it('answers a repeated event with 200 and records it once', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload())->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($institution, capturePayload())->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('webhook_logs', ['outcome' => 'duplicate', 'idempotency_key' => 'payment.capture:py-0001']);
    });

    it('changes the state exactly once when the gateway sends the same event six times', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $body = json_encode(capturePayload());

        $outcomes = collect(range(1, 6))->map(fn () => sendSignedWebhook($institution, $body)->assertOk()->json('outcome'));

        expect($outcomes->all())->toBe(['accepted', 'duplicate', 'duplicate', 'duplicate', 'duplicate', 'duplicate'])
            ->and($invoice->fresh()->status->value)->toBe('paid');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('webhook_logs', 6);
    });

    it('pays only the signing institution invoice when another institution has the same invoice number', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $otherInvoice = Invoice::factory()->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload())->assertOk()->assertJson(['outcome' => 'accepted']);

        expect($invoice->fresh()->status->value)->toBe('paid')
            ->and($otherInvoice->fresh()->status->value)->toBe('unpaid');
        $this->assertDatabaseMissing('transactions', ['invoice_id' => $otherInvoice->id]);
    });

    it('marks an unpaid invoice expired on the gateway expiry event', function (string $event) {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, expiryPayload(event: $event))->assertOk()->assertJson(['outcome' => 'accepted']);

        expect($invoice->fresh()->status->value)->toBe('expired');
        $this->assertDatabaseHas('transactions', [
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'payment_request.expiry:pr-0001',
            'kind' => 'expiry',
            'amount' => null,
            'gateway_payment_id' => null,
        ]);
    })->with(['payment_request.expiry', 'payment.expired']);

    it('keeps a paid invoice paid when the expiry event arrives', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload())->assertOk();
        sendSignedWebhook($institution, expiryPayload())->assertOk()->assertJson(['outcome' => 'accepted']);

        expect($invoice->fresh()->status->value)->toBe('paid');
        $this->assertDatabaseCount('transactions', 2);
    });

    it('rolls back on an unexpected error and logs it separately', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $this->mock(CalculateInvoiceStatus::class)->shouldReceive('handle')->andThrow(new RuntimeException('Calculator failed.'));

        sendSignedWebhook($institution, capturePayload())->assertServerError();

        expect($invoice->fresh()->status->value)->toBe('unpaid');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseMissing('webhook_logs', ['outcome' => 'accepted']);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'error', 'idempotency_key' => 'payment.capture:py-0001']);
    });
});
