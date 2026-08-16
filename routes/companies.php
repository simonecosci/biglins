<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CurrentCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('current-company', [CurrentCompanyController::class, 'update'])->name('current-company.update');
    Route::get('companies/{company}/logo', [CompanyController::class, 'logo'])->name('companies.logo');
    Route::resource('companies', CompanyController::class)->except('show');
});
