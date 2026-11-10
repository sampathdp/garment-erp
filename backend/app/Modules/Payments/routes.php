<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
    Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->whereNumber('payment')->name('payments.verify');
    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->whereNumber('payment')->name('payments.reject');
    Route::get('payments/{payment}/evidence', [PaymentController::class, 'evidence'])->whereNumber('payment')->middleware('signed')->name('payments.evidence');

    Route::get('orders/{order}/payments', [PaymentController::class, 'forOrder'])->whereNumber('order')->name('orders.payments.index');
    Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->whereNumber('order')->middleware('throttle:uploads')->name('orders.payments.store');
});
