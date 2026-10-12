<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('generates all API contracts without querying domain data or sending requests', function () {
    $directory = storage_path('framework/testing/scribe-'.Str::uuid());
    File::ensureDirectoryExists($directory);
    config([
        'scribe.static.output_path' => $directory.'/docs',
        'scribe.base_url' => 'http://localhost',
    ]);
    $domainQueries = [];
    DB::listen(function ($query) use (&$domainQueries) {
        if (preg_match('/\b(invoices|transactions|webhook_logs|institutions|users|personal_access_tokens)\b/i', $query->sql) === 1) {
            $domainQueries[] = $query->sql;
        }
    });

    try {
        $this->artisan('scribe:generate', ['--scribe-dir' => $directory.'/cache', '--no-interaction' => true])
            ->assertSuccessful();

        $specification = Yaml::parseFile($directory.'/docs/openapi.yaml');
        expect(array_keys($specification['paths']))->toBe([
            '/api/v1/invoices',
            '/api/v1/invoices/{invoice_number}',
            '/api/v1/webhooks/payments',
        ]);
        expect(array_keys($specification['paths']['/api/v1/invoices']))->toBe(['get', 'post']);
        expect($specification['paths']['/api/v1/invoices/{invoice_number}']['parameters'][0])
            ->toMatchArray(['name' => 'invoice_number', 'in' => 'path', 'required' => true]);
        expect($specification['paths']['/api/v1/invoices']['post']['responses'])->toHaveKey(201);
        expect($specification['paths']['/api/v1/webhooks/payments']['post']['responses'])
            ->toHaveKeys([200, 202, 401, 422]);
        expect($specification['paths']['/api/v1/webhooks/payments']['post']['security'])->toBe([]);
        expect(File::get($directory.'/docs/index.html'))
            ->toContain('List a student', 'Issue an invoice', 'Show an invoice', 'Receive a signed gateway notification');
        $collection = json_decode(File::get($directory.'/docs/collection.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(collect($collection['item'])->sum(fn (array $group): int => count($group['item'])))->toBe(4);
        expect($domainQueries)->toBe([]);
        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('loads documentation config when the development package is absent', function () {
    $process = new Process([
        PHP_BINARY,
        '-r',
        'exit((require $argv[1]) === [] ? 0 : 1);',
        config_path('scribe.php'),
    ]);

    $process->run();

    expect($process->isSuccessful())->toBeTrue();
});
