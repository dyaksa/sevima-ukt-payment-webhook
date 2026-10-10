<?php

use App\Http\Middleware\SetInstitutionContext;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'institution' => SetInstitutionContext::class,
            'webhook.signature' => VerifyWebhookSignature::class,
        ]);

        // These decode a JSON body; a webhook body must stay unread until its signature is verified.
        $isWebhook = fn (Request $request): bool => $request->is('api/v1/webhooks/*');
        $middleware->trimStrings(except: [$isWebhook]);
        $middleware->convertEmptyStringsToNull(except: [$isWebhook]);

        // Route model binding runs institution-scoped queries, so the context
        // must be set first; authentication already precedes this slot.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetInstitutionContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
