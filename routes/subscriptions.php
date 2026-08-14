<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('subscriptions/{invoice}/renew', [SubscriptionController::class, 'renew'])->name('subscriptions.renew');
    Route::post('subscriptions/{invoice}/cancel', [SubscriptionController::class, 'cancelGroup'])->name('subscriptions.cancel');
    Route::post('invoice-rows/{invoiceRow}/cancel', [SubscriptionController::class, 'cancelRow'])->name('invoice-rows.cancel');
});
