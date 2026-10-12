<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

if (! class_exists(Defaults::class)) {
    return [];
}

return [
    'title' => 'UKT Payment API',
    'description' => 'Institution-isolated tuition invoices and signed mock gateway notifications.',
    'intro_text' => 'All examples are synthetic. Invoice endpoints require a staff token; webhooks require an institution code and an HMAC over the exact request body. See README.md for setup, simulator scenarios and hand-signed requests.',
    'base_url' => config('app.url'),
    'routes' => [
        [
            'match' => ['prefixes' => ['api/v1/*'], 'domains' => ['*']],
            'include' => [],
            'exclude' => [],
        ],
    ],
    'type' => 'static',
    'theme' => 'default',
    'static' => ['output_path' => 'public/docs'],
    'laravel' => ['add_routes' => false],
    'try_it_out' => ['enabled' => false],
    'auth' => [
        'enabled' => true,
        'default' => false,
        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => null,
        'placeholder' => '{STAFF_TOKEN}',
        'extra_info' => 'Run `vendor/bin/sail artisan token:issue finance@univ-a.test`. Read routes require `invoices:read`; issuing an invoice requires `invoices:write`. Tokens belong to the staff user\'s institution. Webhooks use HMAC headers instead of a bearer token.',
    ],
    'example_languages' => ['bash'],
    'postman' => ['enabled' => true],
    'openapi' => ['enabled' => true, 'version' => '3.0.3'],
    'groups' => ['default' => 'Invoices', 'order' => ['Invoices', 'Webhooks']],
    'logo' => false,
    'last_updated' => '',
    'examples' => ['faker_seed' => 1234, 'models_source' => []],
    // Explicit annotations avoid resolving scoped FormRequests or reading live models.
    'strategies' => [
        'metadata' => Defaults::METADATA_STRATEGIES,
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [Strategies\UrlParameters\GetFromUrlParamTag::class],
        'queryParameters' => [Strategies\QueryParameters\GetFromQueryParamTag::class],
        'bodyParameters' => [Strategies\BodyParameters\GetFromBodyParamTag::class],
        // No response calls, resource factories, database samples or runtime credentials.
        'responses' => [Strategies\Responses\UseResponseTag::class],
        'responseFields' => Defaults::RESPONSE_FIELDS_STRATEGIES,
    ],
    'database_connections_to_transact' => [],
];
