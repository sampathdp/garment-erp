<?php

declare(strict_types=1);

use App\Modules\Delivery\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery')->name('deliveries.show');
    Route::post('deliveries/{delivery}/dispatch', [DeliveryController::class, 'dispatch'])->whereNumber('delivery')->name('deliveries.dispatch');
    Route::post('deliveries/{delivery}/deliver', [DeliveryController::class, 'deliver'])->whereNumber('delivery')->name('deliveries.deliver');
    Route::post('deliveries/{delivery}/documents', [DeliveryController::class, 'storeDocument'])->whereNumber('delivery')->middleware('throttle:uploads')->name('deliveries.documents.store');
    Route::get('deliveries/{delivery}/documents/{document}', [DeliveryController::class, 'download'])->whereNumber(['delivery', 'document'])->middleware('signed')->name('deliveries.documents.download');
    Route::post('deliveries/{delivery}/complete', [DeliveryController::class, 'complete'])->whereNumber('delivery')->name('deliveries.complete');
});
