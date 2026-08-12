<?php

use App\Http\Controllers\CountryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('countries', CountryController::class)->except('show');
});
