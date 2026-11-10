<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->name('customers.destroy');
});
