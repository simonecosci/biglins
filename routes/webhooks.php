<?php

use App\Http\Controllers\EInvoicingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/einvoicing/{driver}/{integration}', EInvoicingWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('einvoicing.webhook');
