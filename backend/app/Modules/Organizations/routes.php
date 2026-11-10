<?php

declare(strict_types=1);

use App\Modules\Organizations\Http\Controllers\BranchController;
use App\Modules\Organizations\Http\Controllers\PageController;
use App\Modules\Organizations\Http\Controllers\WorkflowSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->whereNumber('branch')->name('branches.show');
    Route::patch('branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->name('branches.update');

    Route::get('pages', [PageController::class, 'index'])->name('pages.index');
    Route::post('pages', [PageController::class, 'store'])->name('pages.store');
    Route::get('pages/{page}', [PageController::class, 'show'])->whereNumber('page')->name('pages.show');
    Route::patch('pages/{page}', [PageController::class, 'update'])->whereNumber('page')->name('pages.update');

    Route::get('settings/workflow', [WorkflowSettingsController::class, 'show'])->name('settings.workflow.show');
    Route::put('settings/workflow', [WorkflowSettingsController::class, 'update'])->name('settings.workflow.update');
});
