<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows staff to view an invoice of their own institution', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->for($user->institution)->create();

    expect(Gate::forUser($user)->inspect('view', $invoice)->allowed())->toBeTrue();
});

it('denies viewing another institution invoice as not found', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();

    $response = Gate::forUser($user)->inspect('view', $invoice);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});
