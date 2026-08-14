<?php

use App\Http\Controllers\EstimationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('estimations/markdown-preview', [EstimationController::class, 'markdownPreview'])->name('estimations.markdown-preview');
    Route::get('estimations/{estimation}/preview', [EstimationController::class, 'preview'])->name('estimations.preview');
    Route::get('estimations/{estimation}/pdf', [EstimationController::class, 'pdf'])->name('estimations.pdf');
    Route::resource('estimations', EstimationController::class)->except('show');
});
