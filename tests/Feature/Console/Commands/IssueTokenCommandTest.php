<?php

use App\Enums\TokenAbility;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

it('issues a token with both invoice abilities by default', function () {
    $user = User::factory()->create(['email' => 'finance@univ-a.test']);

    $exitCode = Artisan::call('token:issue', ['email' => 'finance@univ-a.test']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($user->tokens()->sole()->abilities)->toBe([TokenAbility::InvoicesRead->value, TokenAbility::InvoicesWrite->value]);
});

it('issues a token limited to the requested ability', function () {
    $user = User::factory()->create(['email' => 'finance@univ-a.test']);

    Artisan::call('token:issue', ['email' => 'finance@univ-a.test', '--ability' => [TokenAbility::InvoicesRead->value]]);

    expect($user->tokens()->sole()->abilities)->toBe([TokenAbility::InvoicesRead->value]);
});

it('prints a token that authenticates API requests for the user institution', function () {
    $user = User::factory()->create(['email' => 'finance@univ-a.test']);
    Invoice::factory()->for($user->institution)->create(['invoice_number' => 'INV-2026-0001']);

    Artisan::call('token:issue', ['email' => 'finance@univ-a.test']);
    $plainTextToken = trim(collect(explode("\n", trim(Artisan::output())))->last());

    $this->withToken($plainTextToken)
        ->getJson(route('v1.invoices.show', 'INV-2026-0001'))
        ->assertOk();
});

it('fails for an email that no user has', function () {
    $exitCode = Artisan::call('token:issue', ['email' => 'nobody@univ-a.test']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('No user has the email [nobody@univ-a.test].');
});

it('fails for an unknown ability without issuing a token', function () {
    $user = User::factory()->create(['email' => 'finance@univ-a.test']);

    $exitCode = Artisan::call('token:issue', ['email' => 'finance@univ-a.test', '--ability' => ['invoices:delete']]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Unknown ability: invoices:delete.')
        ->and($user->tokens()->count())->toBe(0);
});
