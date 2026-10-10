<?php

use App\Models\Institution;
use Database\Seeders\InstitutionSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * @return list<string>
 */
function configureSeedSecrets(): array
{
    $secrets = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];

    foreach ($secrets as $index => $secret) {
        config(["institutions.seed.{$index}.secret" => $secret]);
    }

    return $secrets;
}

it('creates the configured institutions with their environment secrets and one staff user each', function () {
    $secrets = configureSeedSecrets();

    $this->seed(InstitutionSeeder::class);

    $institutions = Institution::orderBy('code')->get();

    expect($institutions->pluck('code')->all())->toBe(['UNIV-A', 'UNIV-B', 'UNIV-C'])
        ->and($institutions->pluck('hmac_secret')->all())->toBe($secrets)
        ->and($institutions->map(fn (Institution $institution) => $institution->users()->count())->all())->toBe([1, 1, 1]);
});

it('generates a secret and prints it once when the environment leaves it empty', function () {
    $secrets = configureSeedSecrets();
    config(['institutions.seed.1.secret' => null]);

    Artisan::call('db:seed', ['--class' => InstitutionSeeder::class, '--force' => true]);
    $output = Artisan::output();

    $generatedSecret = Institution::firstWhere('code', 'UNIV-B')->hmac_secret;

    expect($generatedSecret)->toMatch(Institution::SECRET_PATTERN)
        ->and($output)->toContain("Generated HMAC secret for [UNIV-B], shown once: {$generatedSecret}")
        ->and($output)->not->toContain($secrets[0])
        ->and($output)->not->toContain($secrets[2]);
});

it('rejects a malformed secret from the environment without creating that institution', function () {
    configureSeedSecrets();
    config(['institutions.seed.0.secret' => 'not-a-hex-secret']);

    expect(fn () => $this->seed(InstitutionSeeder::class))
        ->toThrow(RuntimeException::class, 'The HMAC secret for [UNIV-A] in the environment must be 64 hexadecimal characters.');

    $this->assertDatabaseMissing('institutions', ['code' => 'UNIV-A']);
});

it('leaves existing institutions untouched when run again', function () {
    $secrets = configureSeedSecrets();
    $this->seed(InstitutionSeeder::class);

    configureSeedSecrets();
    $this->seed(InstitutionSeeder::class);

    expect(Institution::count())->toBe(3)
        ->and(Institution::orderBy('code')->pluck('hmac_secret')->all())->toBe($secrets);
});
