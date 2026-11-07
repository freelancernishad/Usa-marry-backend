<?php

use Illuminate\Support\Facades\Route;
use PaymentGateway\EPS\Controllers\EpsPaymentController;

/*
|--------------------------------------------------------------------------
| EPS Payment Gateway API Routes
|--------------------------------------------------------------------------
| These routes are automatically loaded by the EpsServiceProvider.
*/

Route::prefix('api/eps')->group(function () {
    // Middleware for authenticated checkout
    $authMiddleware = class_exists(\App\Http\Middleware\AuthenticateUser::class)
        ? \App\Http\Middleware\AuthenticateUser::class
        : 'auth:api';

    Route::middleware($authMiddleware)->group(function () {
        Route::post('/initiate', [EpsPaymentController::class, 'initiate'])->name('eps.api.initiate');
    });

    // Webhooks & Status Check (no user auth needed, secured via merchantTransactionId & server-to-server EPS check)
    Route::post('/ipn', [EpsPaymentController::class, 'ipn'])->name('eps.api.ipn');
    Route::post('/webhook', [EpsPaymentController::class, 'ipn'])->name('eps.api.webhook');
    Route::get('/status/{merchantTxId}', [EpsPaymentController::class, 'status'])->name('eps.api.status');
});

