<?php

use App\Http\Controllers\EstimationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('estimations/markdown-preview', [EstimationController::class, 'markdownPreview'])->name('estimations.markdown-preview');
    Route::resource('estimations', EstimationController::class)->except('show');
});
