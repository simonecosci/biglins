<?php

use App\Http\Controllers\EstimationAttachmentController;
use App\Http\Controllers\EstimationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('estimations/markdown-preview', [EstimationController::class, 'markdownPreview'])->name('estimations.markdown-preview');
    Route::get('estimations/{estimation}/preview', [EstimationController::class, 'preview'])->name('estimations.preview');
    Route::get('estimations/{estimation}/pdf', [EstimationController::class, 'pdf'])->name('estimations.pdf');
    Route::get('estimations/{estimation}/zip', [EstimationController::class, 'zip'])->name('estimations.zip');
    Route::post('estimations/{estimation}/attachments', [EstimationAttachmentController::class, 'store'])->name('estimations.attachments.store');
    Route::delete('estimations/{estimation}/attachments/{attachment}', [EstimationAttachmentController::class, 'destroy'])->name('estimations.attachments.destroy');
    Route::post('estimations/{estimation}/convert-to-invoice', [EstimationController::class, 'convertToInvoice'])->name('estimations.convert-to-invoice');
    Route::resource('estimations', EstimationController::class)->except('show');
});
