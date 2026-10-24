<?php

use App\Http\Controllers\ChirpController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChirpController::class, 'index'])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('chirps', [ChirpController::class, 'store'])
        ->middleware('throttle:chirps')
        ->name('chirps.store');
});

require __DIR__.'/settings.php';
