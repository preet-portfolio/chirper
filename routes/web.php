<?php

use App\Http\Controllers\ChirpController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChirpController::class, 'index'])->name('home');

Route::post('chirps', [ChirpController::class, 'store'])
    ->middleware('throttle:chirps')
    ->name('chirps.store');

Route::get('chirps/{chirp}/edit', [ChirpController::class, 'edit'])->name('chirps.edit');
Route::put('chirps/{chirp}', [ChirpController::class, 'update'])
    ->middleware('throttle:chirps')
    ->name('chirps.update');
Route::delete('chirps/{chirp}', [ChirpController::class, 'destroy'])
    ->middleware('throttle:chirps')
    ->name('chirps.destroy');
