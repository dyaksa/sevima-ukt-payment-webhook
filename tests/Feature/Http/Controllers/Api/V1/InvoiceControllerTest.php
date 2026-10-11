<?php

use App\Enums\TokenAbility;
use App\Http\Middleware\SetInstitutionContext;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Scopes\InstitutionScope;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
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

    it('rejects an identifier with a control character with 422', function (string $field, string $controlCharacter) {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);
        $payload = invoicePayload();
        $payload[$field] .= "{$controlCharacter}junk";

        $this->postJson(route('v1.invoices.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('invoices', 0);
    })->with([
        'NUL in invoice number' => ['invoice_number', "\u{0000}"],
        'unit separator in invoice number' => ['invoice_number', "\u{001F}"],
        'NUL in student number' => ['student_number', "\u{0000}"],
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

describe('index', function () {
    it('lists only the caller institution invoices of one student number', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-OWN', 'student_number' => '2021000123']);
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-OTHER-STUDENT', 'student_number' => '2021000999']);
        Invoice::factory()->create(['invoice_number' => 'INV-OTHER-INSTITUTION', 'student_number' => '2021000123']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV-OWN')
            ->assertJsonPath('data.0.status', 'unpaid');
    });

    it('still lists only the caller institution invoices when the institution context is wrong', function () {
        $user = User::factory()->create();
        $otherInvoice = Invoice::factory()->create(['student_number' => '2021000123']);
        app()->instance(SetInstitutionContext::class, new class($otherInvoice->institution_id)
        {
            public function __construct(private string $institutionId) {}

            public function handle(Request $request, Closure $next): mixed
            {
                Context::add(InstitutionScope::CONTEXT_KEY, $this->institutionId);

                return $next($request);
            }
        });
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('filters a student invoices by semester', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-ODD', 'student_number' => '2021000123', 'semester' => 'SM-2026-1']);
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-EVEN', 'student_number' => '2021000123', 'semester' => 'SM-2026-2']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123', 'semester' => 'SM-2026-2']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV-EVEN');
    });

    it('treats an empty semester filter as no filter', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->count(2)->sequence(['semester' => 'SM-2026-1'], ['semester' => 'SM-2026-2'])
            ->create(['student_number' => '2021000123']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123', 'semester' => '']))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('orders a student invoices by newest semester, then by newest invoice', function () {
        $user = User::factory()->create();
        $invoice = fn (string $number, string $semester) => Invoice::factory()->for($user->institution)
            ->create(['invoice_number' => $number, 'student_number' => '2021000123', 'semester' => $semester]);
        $invoice('INV-2026-1-FIRST', 'SM-2026-1');
        $invoice('INV-2025-2', 'SM-2025-2');
        $invoice('INV-2026-1-SECOND', 'SM-2026-1');
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $response = $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))->assertOk();

        expect($response->json('data.*.invoice_number'))->toBe(['INV-2026-1-SECOND', 'INV-2026-1-FIRST', 'INV-2025-2']);
    });

    it('includes each invoice transaction history in event order', function () {
        $user = User::factory()->create();
        $paid = Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-PAID', 'student_number' => '2021000123', 'semester' => 'SM-2026-2']);
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-UNPAID', 'student_number' => '2021000123', 'semester' => 'SM-2026-1']);
        $transaction = fn (string $key, string $occurredAt) => Transaction::factory()->for($paid)
            ->create(['institution_id' => $user->institution_id, 'idempotency_key' => $key, 'occurred_at' => $occurredAt]);
        $transaction('payment.capture:late', '2026-10-02T10:00:00+07:00');
        $transaction('payment.capture:early', '2026-10-01T10:00:00+07:00');
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', 'INV-PAID')
            ->assertJsonPath('data.0.transactions.0.idempotency_key', 'payment.capture:early')
            ->assertJsonPath('data.0.transactions.1.idempotency_key', 'payment.capture:late')
            ->assertJsonPath('data.1.invoice_number', 'INV-UNPAID')
            ->assertJsonPath('data.1.transactions', []);
    });

    it('runs the same number of queries however many invoices the student has', function () {
        $user = User::factory()->create();
        $issueInvoiceWithPayment = fn () => Transaction::factory()
            ->for(Invoice::factory()->for($user->institution)->create(['student_number' => '2021000123']))
            ->create(['institution_id' => $user->institution_id]);
        $countQueries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))->assertOk();

            return count(DB::getQueryLog());
        };
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $issueInvoiceWithPayment();
        $queriesForOneInvoice = $countQueries();
        $issueInvoiceWithPayment();
        $issueInvoiceWithPayment();

        expect($countQueries())->toBe($queriesForOneInvoice);
    });

    it('rejects a malformed semester filter with 422', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123', 'semester' => 'Ganjil 2026']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['semester' => 'The semester must look like SM-2026-1: the SM prefix, a year, and a term number from 1 to 3.']);
    });

    it('rejects a request without a student number with 422', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesRead->value]);

        $this->getJson(route('v1.invoices.index'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_number']);
    });

    it('returns 403 when the token lacks the read ability', function () {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::InvoicesWrite->value]);

        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))->assertForbidden();
    });

    it('returns 401 when no token is provided', function () {
        $this->getJson(route('v1.invoices.index', ['student_number' => '2021000123']))->assertUnauthorized();
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

    it('returns 404 for an invoice number with a control character', function () {
        $user = User::factory()->create();
        Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001']);
        Sanctum::actingAs($user, [TokenAbility::InvoicesRead->value]);

        $this->getJson('/api/v1/invoices/INV-2026-0001%00junk')->assertNotFound();
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
