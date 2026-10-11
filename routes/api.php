<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Rules\Identifier;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->middleware(['auth:sanctum', 'institution'])->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->middleware(TokenAbility::InvoicesRead->middleware())
        ->name('invoices.index');

    Route::post('invoices', [InvoiceController::class, 'store'])
        ->middleware(TokenAbility::InvoicesWrite->middleware())
        ->name('invoices.store');

    Route::get('invoices/{invoice:invoice_number}', [InvoiceController::class, 'show'])
        ->where('invoice', Identifier::PATTERN)
        ->middleware(TokenAbility::InvoicesRead->middleware())
        ->name('invoices.show');
});

// The gateway is authenticated by the HMAC signature only, not by Sanctum.
Route::post('v1/webhooks/payments', [WebhookController::class, 'store'])
    ->middleware('webhook.signature')
    ->name('v1.webhooks.store');
