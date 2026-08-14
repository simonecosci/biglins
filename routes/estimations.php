<?php

use App\Http\Controllers\EstimationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('estimations', EstimationController::class)->except('show');
});
