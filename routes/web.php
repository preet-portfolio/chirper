<?php

use App\Http\Controllers\ChirpController;
use App\Http\Middleware\EnsureEmailIsVerifiedWhenRequired;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChirpController::class, 'index'])->name('home');

Route::middleware(['auth', EnsureEmailIsVerifiedWhenRequired::class])->group(function () {
    Route::post('chirps', [ChirpController::class, 'store'])
        ->middleware('throttle:chirps')
        ->name('chirps.store');

    Route::get('chirps/{chirp}/edit', [ChirpController::class, 'edit'])->name('chirps.edit');
    Route::put('chirps/{chirp}', [ChirpController::class, 'update'])->name('chirps.update');
    Route::delete('chirps/{chirp}', [ChirpController::class, 'destroy'])->name('chirps.destroy');
});

require __DIR__.'/settings.php';
