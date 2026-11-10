<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\MaterialController;
use App\Modules\Pricing\Http\Controllers\PriceListController;
use App\Modules\Pricing\Http\Controllers\ProductCategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('product-categories', [ProductCategoryController::class, 'index'])->name('product-categories.index');
    Route::post('product-categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
    Route::patch('product-categories/{id}', [ProductCategoryController::class, 'update'])->whereNumber('id')->name('product-categories.update');

    Route::get('materials', [MaterialController::class, 'index'])->name('materials.index');
    Route::post('materials', [MaterialController::class, 'store'])->name('materials.store');
    Route::patch('materials/{id}', [MaterialController::class, 'update'])->whereNumber('id')->name('materials.update');

    Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
    Route::post('price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
    Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->whereNumber('priceList')->name('price-lists.show');
    Route::patch('price-lists/{priceList}', [PriceListController::class, 'update'])->whereNumber('priceList')->name('price-lists.update');
    Route::post('price-lists/{priceList}/rules', [PriceListController::class, 'storeRule'])->whereNumber('priceList')->name('price-lists.rules.store');
    Route::patch('price-lists/{priceList}/rules/{rule}', [PriceListController::class, 'updateRule'])->whereNumber(['priceList', 'rule'])->name('price-lists.rules.update');
    Route::delete('price-lists/{priceList}/rules/{rule}', [PriceListController::class, 'destroyRule'])->whereNumber(['priceList', 'rule'])->name('price-lists.rules.destroy');
});
