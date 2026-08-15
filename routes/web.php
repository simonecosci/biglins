<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/companies.php';
require __DIR__.'/countries.php';
require __DIR__.'/customers.php';
require __DIR__.'/estimations.php';
require __DIR__.'/invoices.php';
require __DIR__.'/notes.php';
require __DIR__.'/products.php';
require __DIR__.'/subscriptions.php';
