<?php

use Illuminate\Support\Facades\Route;
use PaymentGateway\EPS\Controllers\EpsPaymentController;

/*
|--------------------------------------------------------------------------
| EPS Payment Gateway Web Callback Routes
|--------------------------------------------------------------------------
| These routes handle redirects when a customer returns from the EPS Gateway.
*/

Route::match(['get', 'post'], '/eps/success', [EpsPaymentController::class, 'success'])->name('eps.web.success');
Route::match(['get', 'post'], '/eps/fail', [EpsPaymentController::class, 'fail'])->name('eps.web.fail');
Route::match(['get', 'post'], '/eps/cancel', [EpsPaymentController::class, 'cancel'])->name('eps.web.cancel');

