<?php

use App\Modules\Subscriptions\Http\Controllers\PaymentSimulatorController;
use Illuminate\Support\Facades\Route;

// The API serves no web pages, except the development-only payment simulator.
if (app()->environment(['local', 'testing']) && config('subscriptions.gateway') === 'simulator') {
    Route::get('dev/payment-simulator/{paymentId}', [PaymentSimulatorController::class, 'show'])->name('dev.payment-simulator.show');
    Route::post('dev/payment-simulator/{paymentId}/{decision}', [PaymentSimulatorController::class, 'decide'])->name('dev.payment-simulator.decide');
}
