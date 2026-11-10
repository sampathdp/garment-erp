<?php

declare(strict_types=1);

use App\Modules\Invoices\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('orders/{order}/invoices', [InvoiceController::class, 'forOrder'])->whereNumber('order')->name('orders.invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
});
