<?php

use App\Enums\TokenAbility;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * @return array<string, mixed>
 */
function invoicePayload(array $overrides = []): array
{
    return [
        'invoice_number' => 'INV-2026-0001',
        'student_number' => '2021000123',
        'semester' => 'SM-2026-1',
        'amount' => 4_500_000,
        'expires_at' => now()->addMonth()->toIso8601String(),
        ...$overrides,
    ];
}

describe('store', function () {
    it('issues an unpaid invoice for the caller institution', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload())
            ->assertCreated()
            ->assertJsonPath('data.invoice_number', 'INV-2026-0001')
            ->assertJsonPath('data.amount', 4_500_000)
            ->assertJsonPath('data.status', 'unpaid');

        $this->assertDatabaseHas('invoices', [
            'institution_id' => $user->institution_id,
            'invoice_number' => 'INV-2026-0001',
            'amount' => 4_500_000,
            'status' => 'unpaid',
        ]);
    });

    it('ignores an institution id sent in the request body', function () {
        $user = User::factory()->create();
        $otherInstitution = Institution::factory()->create();
        Sanctum::actingAs($user, [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload(['institution_id' => $otherInstitution->id]))
            ->assertCreated();

        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-2026-0001', 'institution_id' => $user->institution_id]);
        $this->assertDatabaseMissing('invoices', ['institution_id' => $otherInstitution->id]);
    });

    it('stores a digit string amount as an integer', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload(['amount' => '4500000']))
            ->assertCreated()
            ->assertJsonPath('data.amount', 4_500_000);
    });

    it('rejects a fractional amount with 422', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload(['amount' => 4_500_000.5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount must be a whole rupiah amount from 1 to 1000000000000.']);
    });

    it('rejects an invoice number already used in the same institution with 422', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_number' => 'The invoice number has already been taken.']);
    });

    it('accepts an invoice number that another institution already uses', function () {
        Invoice::factory()->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload())->assertCreated();

        $this->assertDatabaseCount('invoices', 2);
    });

    it('rejects an expiry date in the past with 422', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload(['expires_at' => now()->subDay()->toIso8601String()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at' => 'The expires at field must be a date after now.']);
    });

    it('rejects a malformed semester with 422', function (string $semester) {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload(['semester' => $semester]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['semester' => 'The semester must look like SM-2026-1: the SM prefix, a year, and a term number from 1 to 3.']);
    })->with([
        'missing prefix' => '2026-1',
        'lowercase prefix' => 'sm-2026-1',
        'term out of range' => 'SM-2026-4',
        'free text' => 'Ganjil 2026',
    ]);

    it('rejects an empty payload with 422 listing every required field', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->postJson(route('v1.invoices.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_number', 'student_number', 'semester', 'amount', 'expires_at']);
    });

    it('returns 403 when the token lacks the write ability', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesRead->value]);

        $this->postJson(route('v1.invoices.store'), invoicePayload())->assertForbidden();

        $this->assertDatabaseCount('invoices', 0);
    });

    it('returns 401 when no token is provided', function () {
        $this->postJson(route('v1.invoices.store'), invoicePayload())->assertUnauthorized();
    });
});

describe('show', function () {
    it('returns an invoice of the caller institution by its number', function () {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-2026-0001')
            ->assertJsonPath('data.amount', $invoice->amount)
            ->assertJsonPath('data.status', 'unpaid');
    });

    it('returns 404 for an invoice that belongs to another institution', function () {
        Invoice::factory()->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))->assertNotFound();
    });

    it('returns the caller institution invoice when another institution uses the same number', function () {
        $user = User::factory()->create();
        Invoice::factory()->create(['invoice_number' => 'INV-2026-0001', 'amount' => 9_000_000]);
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001', 'amount' => 4_500_000]);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))
            ->assertOk()
            ->assertJsonPath('data.amount', 4_500_000);
    });

    it('returns 403 when the token lacks the read ability', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesWrite->value]);

        $this->getJson(route('v1.invoices.show', 'INV-2026-0001'))->assertForbidden();
    });
});
