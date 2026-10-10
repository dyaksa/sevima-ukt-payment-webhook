<?php

use App\Models\Institution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

it('creates an institution and its staff user and shows the secret once', function () {
    $exitCode = Artisan::call('institution:create', [
        'code' => 'UNIV-D',
        'name' => 'University D',
        '--staff-email' => 'finance@univ-d.test',
    ]);

    $institution = Institution::firstWhere('code', 'UNIV-D');

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($institution->hmac_secret)->toMatch('/^[0-9a-f]{64}$/')
        ->and(Artisan::output())->toContain($institution->hmac_secret)
        ->and($institution->users()->pluck('email')->all())->toBe(['finance@univ-d.test']);
});

it('rejects a malformed institution code', function () {
    $exitCode = Artisan::call('institution:create', ['code' => 'univ d', 'name' => 'University D']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('must be 3 to 32 uppercase letters, digits, or hyphens');

    $this->assertDatabaseCount('institutions', 0);
});

it('rejects an institution code that is already taken', function () {
    Institution::factory()->create(['code' => 'UNIV-D']);

    $exitCode = Artisan::call('institution:create', ['code' => 'UNIV-D', 'name' => 'Another University']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('already taken');

    $this->assertDatabaseCount('institutions', 1);
});
