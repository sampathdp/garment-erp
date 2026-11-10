<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\JobDetailsController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\OrderTypeController;
use Illuminate\Support\Facades\Route;

/*
| There is deliberately no endpoint that sets orders.status. Status changes only happen through
| workflow actions (submit, payment decisions, urgent decisions) via TransitionOrder.
*/
Route::middleware('authenticated')->group(function (): void {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->whereNumber('order')->name('orders.update');
    Route::post('orders/{order}/submit', [OrderController::class, 'submit'])->whereNumber('order')->name('orders.submit');
    Route::post('orders/{order}/urgent-decisions', [OrderController::class, 'decideUrgent'])->whereNumber('order')->name('orders.urgent-decisions');

    Route::post('orders/{order}/job-details', [JobDetailsController::class, 'update'])->whereNumber('order')->middleware('throttle:uploads')->name('orders.job-details.update');
    Route::post('orders/{order}/job-details/submit', [JobDetailsController::class, 'submit'])->whereNumber('order')->name('orders.job-details.submit');
    Route::get('orders/{order}/job-details/attachment', [JobDetailsController::class, 'attachment'])->whereNumber('order')->middleware('signed')->name('orders.job-details.attachment');

    Route::get('order-types', [OrderTypeController::class, 'index'])->name('order-types.index');
    Route::post('order-types', [OrderTypeController::class, 'store'])->name('order-types.store');
    Route::patch('order-types/{id}', [OrderTypeController::class, 'update'])->whereNumber('id')->name('order-types.update');
});
