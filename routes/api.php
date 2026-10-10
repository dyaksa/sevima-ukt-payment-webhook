<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->middleware(['auth:sanctum', 'institution'])->group(function () {
    Route::post('invoices', [InvoiceController::class, 'store'])
        ->middleware(TokenAbility::InvoicesWrite->middleware())
        ->name('invoices.store');

    Route::get('invoices/{invoice:invoice_number}', [InvoiceController::class, 'show'])
        ->middleware(TokenAbility::InvoicesRead->middleware())
        ->name('invoices.show');
});
