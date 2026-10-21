<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceSubmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('invoices/{invoice}/issue', [InvoiceSubmissionController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/refresh-status', [InvoiceSubmissionController::class, 'refresh'])->name('invoices.refresh-status');
    Route::resource('invoices', InvoiceController::class)->except('show');
});
