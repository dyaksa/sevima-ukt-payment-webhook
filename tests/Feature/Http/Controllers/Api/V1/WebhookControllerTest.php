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
 * A Xendit-shaped refund linked to a payment, not an invoice number.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function refundPayload(array $data = [], string $event = 'refund.succeeded'): array
{
    return [
        'event' => $event,
        'business_id' => 'UNIV-A',
        'created' => '2026-10-10T09:00:01.000Z',
        'data' => [
            'id' => 'rf-0001',
            'payment_id' => 'py-0001',
            'currency' => 'IDR',
            'amount' => 4_500_000,
            'status' => 'SUCCEEDED',
            'reason' => 'REQUESTED_BY_CUSTOMER',
            'failure_code' => null,
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

    it('rejects a fractional refund amount with 422 and audits the rejection', function () {
        $institution = Institution::factory()->create();
        $body = str_replace('"amount":4500000', '"amount":4500000.5', json_encode(refundPayload()));

        sendSignedWebhook($institution, $body)->assertUnprocessable()->assertJsonValidationErrors('data.amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid', 'raw_body' => $body]);
    });

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

    it('rejects an event without the fields it requires with 422', function (array $payload, string $missingField) {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);
        unset($payload['data'][$missingField]);

        sendSignedWebhook($institution, $payload)->assertUnprocessable()->assertJsonValidationErrors("data.{$missingField}");

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    })->with([
        'capture without a payment id' => [capturePayload(), 'payment_id'],
        'capture without captures' => [capturePayload(), 'captures'],
        'expiry without a payment request id' => [expiryPayload(), 'payment_request_id'],
        'refund without a refund id' => [refundPayload(), 'id'],
        'refund without a payment id' => [refundPayload(), 'payment_id'],
        'refund without an amount' => [refundPayload(), 'amount'],
        'failed refund without a refund id' => [refundPayload(event: 'refund.failed'), 'id'],
    ]);

    it('rejects an event name that is not a string with 422 and logs it', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);

        sendSignedWebhook($institution, [...expiryPayload(), 'event' => ['payment_request.expiry']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    });

    it('rejects an event time PostgreSQL cannot store with 422', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload(['updated' => '0000-01-01T00:00:00Z']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.updated']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    });

    it('rejects an event time more than an hour ahead of the clock with 422', function () {
        $this->travelTo('2026-10-10T08:00:00Z');
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload(['updated' => '2026-10-10T09:00:01Z']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.updated']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    });

    it('accepts an event time ahead of the clock within the one-hour allowance', function () {
        $this->travelTo('2026-10-10T08:00:00Z');
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, capturePayload(['updated' => '2026-10-10T16:00:00+07:00']))
            ->assertOk()
            ->assertJson(['outcome' => 'accepted']);
    });

    it('rejects an identifier with a control character with 422', function (array $payload, string $field, string $controlCharacter) {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $payload['data'][$field] .= "{$controlCharacter}junk";

        sendSignedWebhook($institution, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["data.{$field}"]);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    })->with([
        'NUL in capture reference id' => [capturePayload(), 'reference_id', "\u{0000}"],
        'unit separator in capture reference id' => [capturePayload(), 'reference_id', "\u{001F}"],
        'NUL in capture payment id' => [capturePayload(), 'payment_id', "\u{0000}"],
        'NUL in expiry payment request id' => [expiryPayload(), 'payment_request_id', "\u{0000}"],
        'NUL in refund id' => [refundPayload(), 'id', "\u{0000}"],
    ]);

    it('rejects a refund id longer than 64 characters with 422', function () {
        $institution = Institution::factory()->create();

        sendSignedWebhook($institution, refundPayload(['id' => str_repeat('r', 65)]))
            ->assertUnprocessable()->assertJsonValidationErrors('data.id');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
    });

    it('ignores query string values the signature does not cover', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $payload = capturePayload();
        unset($payload['event']);
        $body = json_encode($payload);

        test()->call('POST', route('v1.webhooks.store', ['event' => 'payment.capture']), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INSTITUTION_CODE' => $institution->code,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $institution->hmac_secret),
        ], content: $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'invalid']);
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

describe('event order and anomalies', function () {
    it('reclassifies a previously received payment when an earlier valid payment arrives later', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($user->institution, capturePayload(['payment_id' => 'py-later', 'updated' => '2026-10-10T09:00:00Z']))
            ->assertOk();
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertJsonPath('data.transactions.0.annotation', null);

        sendSignedWebhook($user->institution, capturePayload(['payment_id' => 'py-earlier', 'updated' => '2026-10-10T08:00:00Z']))
            ->assertOk()
            ->assertJson(['outcome' => 'accepted']);

        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.gateway_payment_id', 'py-earlier')
            ->assertJsonPath('data.transactions.0.annotation', null)
            ->assertJsonPath('data.transactions.1.gateway_payment_id', 'py-later')
            ->assertJsonPath('data.transactions.1.annotation', 'duplicate_payment');
    });

    it('marks a capture received after expiry paid and orders history by event time', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($user->institution, expiryPayload())->assertOk();
        sendSignedWebhook($user->institution, capturePayload())->assertOk();

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.transactions.0.kind', 'payment')
            ->assertJsonPath('data.transactions.1.kind', 'expiry');
    });

    it('includes derived annotations when listing student invoices', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create([
            'invoice_number' => 'INV-2026-0001',
            'student_number' => '2021000123',
            'amount' => 4_500_000,
        ]);

        sendSignedWebhook($user->institution, capturePayload(['captures' => [['capture_amount' => 5_000_000]]]))
            ->assertOk();

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'unpaid')
            ->assertJsonPath('data.0.transactions.0.annotation', 'amount_mismatch');
    });

    it('keeps an underpayment unpaid and exposes the amount mismatch in its history', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($user->institution, capturePayload(['captures' => [['capture_amount' => 4_000_000]]]))
            ->assertOk()
            ->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.transactions.0.amount', 4_000_000)
            ->assertJsonPath('data.transactions.0.annotation', 'amount_mismatch');
    });
});

describe('processing', function () {
    it('records an informational payment event without paying the invoice', function (string $event, string $kind) {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $payload = capturePayload();
        $payload['event'] = $event;
        unset($payload['data']['captures']);

        sendSignedWebhook($user->institution, $payload)->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.kind', $kind)
            ->assertJsonPath('data.transactions.0.amount', null)
            ->assertJsonPath('data.transactions.0.gateway_payment_id', 'py-0001');
    })->with([
        'authorization' => ['payment.authorization', 'pending'],
        'pending alias' => ['payment.pending', 'pending'],
        'failed attempt' => ['payment.failure', 'failed_payment'],
    ]);

    it('accepts a capture after authorization for the same payment without treating it as a retry', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        $authorization = capturePayload(['updated' => '2026-10-10T07:00:00Z']);
        $authorization['event'] = 'payment.authorization';
        unset($authorization['data']['captures']);

        sendSignedWebhook($user->institution, $authorization)->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($user->institution, capturePayload())->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.kind', 'pending')
            ->assertJsonPath('data.transactions.1.kind', 'payment')
            ->assertJsonPath('data.transactions.1.annotation', null);
    });

    it('marks a paid invoice refunded through its payment id and keeps it final', function (string $event) {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();

        sendSignedWebhook($user->institution, refundPayload(event: $event))->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($user->institution, capturePayload(['payment_id' => 'py-late', 'updated' => '2026-10-10T10:00:00Z']))->assertOk();

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonCount(3, 'data.transactions')
            ->assertJsonPath('data.transactions.1.kind', 'refund')
            ->assertJsonPath('data.transactions.1.amount', 4_500_000)
            ->assertJsonPath('data.transactions.1.gateway_payment_id', 'py-0001')
            ->assertJsonPath('data.transactions.1.idempotency_key', 'refund.succeeded:rf-0001')
            ->assertJsonPath('data.transactions.1.annotation', null)
            ->assertJsonPath('data.transactions.2.annotation', 'duplicate_payment');
    })->with(['wire name' => 'refund.succeeded', 'brief alias' => 'payment.refunded']);

    it('flags a partial refund for review without changing the paid status', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();

        sendSignedWebhook($user->institution, refundPayload(['amount' => 500_000]))->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.1.kind', 'refund')
            ->assertJsonPath('data.transactions.1.amount', 500_000)
            ->assertJsonPath('data.transactions.1.annotation', 'needs_review');
    });

    it('accumulates distinct refunds for one payment but counts each refund once', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();
        $partial = refundPayload(['amount' => 4_000_000]);

        sendSignedWebhook($user->institution, $partial)->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($user->institution, $partial)->assertOk()->assertJson(['outcome' => 'duplicate']);
        sendSignedWebhook($user->institution, refundPayload(['id' => 'rf-0002', 'amount' => '500000', 'updated' => '2026-10-10T10:00:00Z']))
            ->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonCount(3, 'data.transactions')
            ->assertJsonPath('data.transactions.1.amount', 4_000_000)
            ->assertJsonPath('data.transactions.2.amount', 500_000);
    });

    it('records a failed refund without changing the paid invoice', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();

        sendSignedWebhook($user->institution, refundPayload(['status' => 'FAILED', 'failure_code' => 'REJECTED'], 'refund.failed'))
            ->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.1.kind', 'failed_refund')
            ->assertJsonPath('data.transactions.1.amount', 4_500_000)
            ->assertJsonPath('data.transactions.1.annotation', null);
    });

    it('returns 202 and audits a refund whose payment has not arrived', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001']);

        sendSignedWebhook($institution, refundPayload())
            ->assertAccepted()
            ->assertJson(['outcome' => 'unmatched']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('webhook_logs', [
            'institution_id' => $institution->id,
            'outcome' => 'unmatched',
            'event' => 'refund.succeeded',
            'idempotency_key' => 'refund.succeeded:rf-0001',
            'invoice_number' => null,
        ]);
    });

    it('returns 202 for a payment id belonging only to another institution', function () {
        $otherUser = User::factory()->create();
        Invoice::factory()->for($otherUser->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($otherUser->institution, capturePayload())->assertOk();
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, refundPayload(['reference_id' => 'INV-2026-0001', 'institution_id' => $otherUser->institution_id]))
            ->assertAccepted()->assertJson(['outcome' => 'unmatched']);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('webhook_logs', ['institution_id' => $institution->id, 'outcome' => 'unmatched']);
        Sanctum::actingAs($otherUser, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonCount(1, 'data.transactions');
    });

    it('refunds only the signing institution when both share a gateway payment id', function () {
        $otherUser = User::factory()->create();
        Invoice::factory()->for($otherUser->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($otherUser->institution, capturePayload())->assertOk();
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();

        sendSignedWebhook($user->institution, refundPayload())->assertOk()->assertJson(['outcome' => 'accepted']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonCount(2, 'data.transactions');
        Sanctum::actingAs($otherUser, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonCount(1, 'data.transactions');
    });

    it('retries an unmatched refund after its payment arrives without losing or duplicating it', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($user->institution, refundPayload())->assertAccepted()->assertJson(['outcome' => 'unmatched']);
        sendSignedWebhook($user->institution, capturePayload())->assertOk();
        sendSignedWebhook($user->institution, refundPayload())->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($user->institution, refundPayload(event: 'payment.refunded'))->assertOk()->assertJson(['outcome' => 'duplicate']);

        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);
        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonCount(2, 'data.transactions');
        $this->assertDatabaseCount('webhook_logs', 4);
    });

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
    })->with([
        'wire name' => 'payment_request.expiry',
        'brief alias' => 'payment.expired',
    ]);

    it('accepts an expiry that sends a null payment id', function () {
        $institution = Institution::factory()->create();
        $invoice = Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, expiryPayload(['payment_id' => null]))->assertOk()->assertJson(['outcome' => 'accepted']);

        expect($invoice->fresh()->status->value)->toBe('expired');
        $this->assertDatabaseHas('transactions', [
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'payment_request.expiry:pr-0001',
            'kind' => 'expiry',
            'gateway_payment_id' => null,
        ]);
    });

    it('answers a repeated expiry with 200 and records it once', function () {
        $institution = Institution::factory()->create();
        Invoice::factory()->for($institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);

        sendSignedWebhook($institution, expiryPayload())->assertOk()->assertJson(['outcome' => 'accepted']);
        sendSignedWebhook($institution, expiryPayload(event: 'payment.expired'))->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('webhook_logs', ['outcome' => 'duplicate', 'idempotency_key' => 'payment_request.expiry:pr-0001']);
    });

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
