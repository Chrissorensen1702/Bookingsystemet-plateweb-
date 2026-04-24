<?php

use App\Http\Controllers\Api\Native\AuthController;
use App\Http\Controllers\Api\Native\NativeAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('native')
    ->name('api.native.')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'store'])
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware('native.api')->group(function (): void {
            Route::get('/me', [AuthController::class, 'show'])->name('me');
            Route::delete('/logout', [AuthController::class, 'destroy'])->name('logout');

            Route::get('/bootstrap', [NativeAppController::class, 'bootstrap'])->name('bootstrap');
            Route::get('/bookings', [NativeAppController::class, 'bookings'])->name('bookings');
            Route::get('/booking-options', [NativeAppController::class, 'bookingOptions'])->name('bookings.options');
            Route::post('/bookings', [NativeAppController::class, 'storeBooking'])->name('bookings.store');
            Route::get('/services', [NativeAppController::class, 'services'])->name('services');
        });
    });
