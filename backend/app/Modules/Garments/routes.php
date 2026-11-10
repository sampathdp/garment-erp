<?php

declare(strict_types=1);

use App\Modules\Garments\Http\Controllers\GarmentListController;
use App\Modules\Garments\Http\Controllers\GarmentSetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('orders/{order}/garment-list', [GarmentListController::class, 'show'])->whereNumber('order')->name('garment-lists.show');
    Route::post('garment-lists/{list}/rows', [GarmentListController::class, 'addRows'])->whereNumber('list')->name('garment-lists.rows.store');
    Route::patch('garment-lists/{list}/rows/{item}', [GarmentListController::class, 'updateRow'])->whereNumber(['list', 'item'])->name('garment-lists.rows.update');
    Route::delete('garment-lists/{list}/rows/{item}', [GarmentListController::class, 'deleteRow'])->whereNumber(['list', 'item'])->name('garment-lists.rows.destroy');
    Route::post('garment-lists/{list}/import', [GarmentListController::class, 'import'])->whereNumber('list')->middleware('throttle:uploads')->name('garment-lists.import');
    Route::get('garment-lists/{list}/export', [GarmentListController::class, 'export'])->whereNumber('list')->middleware('throttle:exports')->name('garment-lists.export');
    Route::post('garment-lists/{list}/confirm', [GarmentListController::class, 'confirm'])->whereNumber('list')->name('garment-lists.confirm');
    Route::post('garment-lists/{list}/corrections', [GarmentListController::class, 'correct'])->whereNumber('list')->name('garment-lists.corrections.store');

    Route::get('garment-setup', [GarmentSetupController::class, 'index'])->name('garment-setup.index');
    Route::post('garment-setup/fields', [GarmentSetupController::class, 'storeField'])->name('garment-setup.fields.store');
    Route::patch('garment-setup/fields/{field}', [GarmentSetupController::class, 'updateField'])->whereNumber('field')->name('garment-setup.fields.update');
    Route::post('garment-setup/option-sets', [GarmentSetupController::class, 'storeOptionSet'])->name('garment-setup.option-sets.store');
    Route::post('garment-setup/option-sets/{set}/options', [GarmentSetupController::class, 'storeOption'])->whereNumber('set')->name('garment-setup.options.store');
    Route::patch('garment-setup/options/{option}', [GarmentSetupController::class, 'updateOption'])->whereNumber('option')->name('garment-setup.options.update');
    Route::post('garment-setup/size-charts', [GarmentSetupController::class, 'storeSizeChart'])->name('garment-setup.size-charts.store');
    Route::post('garment-setup/size-charts/{chart}/sizes', [GarmentSetupController::class, 'storeSize'])->whereNumber('chart')->name('garment-setup.sizes.store');
    Route::patch('garment-setup/sizes/{size}', [GarmentSetupController::class, 'updateSize'])->whereNumber('size')->name('garment-setup.sizes.update');
    Route::patch('garment-setup/categories/{category}', [GarmentSetupController::class, 'setCategoryChart'])->whereNumber('category')->name('garment-setup.categories.update');
});
