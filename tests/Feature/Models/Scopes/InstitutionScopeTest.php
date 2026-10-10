<?php

use App\Exceptions\MissingInstitutionContextException;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Scopes\InstitutionScope;
use Illuminate\Support\Facades\Context;

it('throws instead of returning every institution when no institution context is set', function () {
    Invoice::factory()->create();

    expect(fn () => Invoice::query()->get())->toThrow(MissingInstitutionContextException::class);
});

it('returns only the invoices of the institution in context', function () {
    $institution = Institution::factory()->create();
    $ownInvoice = Invoice::factory()->for($institution)->create();
    Invoice::factory()->create();

    Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);

    expect(Invoice::pluck('id')->all())->toBe([$ownInvoice->id]);
});

it('stamps a new invoice with the institution in context', function () {
    $institution = Institution::factory()->create();
    Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);

    $invoice = Invoice::create([
        'invoice_number' => 'INV-2026-0001',
        'student_number' => '2021000123',
        'semester' => 'SM-2026-1',
        'amount' => 4_500_000,
        'expires_at' => now()->addMonth(),
    ]);

    expect($invoice->institution_id)->toBe($institution->id);
});
