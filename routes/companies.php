<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CurrentCompanyController;
use App\Http\Controllers\EInvoicingIntegrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('current-company', [CurrentCompanyController::class, 'update'])->name('current-company.update');
    Route::get('companies/{company}/logo', [CompanyController::class, 'logo'])->name('companies.logo');
    Route::get('companies/{company}/e-invoicing', [EInvoicingIntegrationController::class, 'edit'])->name('companies.e-invoicing.edit');
    Route::put('companies/{company}/e-invoicing', [EInvoicingIntegrationController::class, 'update'])->name('companies.e-invoicing.update');
    Route::post('companies/{company}/e-invoicing/test', [EInvoicingIntegrationController::class, 'test'])->name('companies.e-invoicing.test');
    Route::resource('companies', CompanyController::class)->except('show');
});
