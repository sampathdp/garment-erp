<?php

declare(strict_types=1);

use App\Modules\Cutting\Http\Controllers\CuttingJobController;
use App\Modules\Cutting\Http\Controllers\CuttingSetupController;
use App\Modules\Cutting\Http\Controllers\CuttingWorkController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('cutting-jobs', [CuttingJobController::class, 'index'])->name('cutting-jobs.index');
    Route::get('cutting-jobs/{job}', [CuttingJobController::class, 'show'])->whereNumber('job')->name('cutting-jobs.show');
    Route::get('cutting-jobs/{job}/job-card', [CuttingJobController::class, 'jobCard'])->whereNumber('job')->name('cutting-jobs.job-card');
    Route::get('cutting-jobs/{job}/name-list', [CuttingJobController::class, 'nameList'])->whereNumber('job')->name('cutting-jobs.name-list');
    Route::get('cutting-jobs/{job}/cutters', [CuttingJobController::class, 'cutters'])->whereNumber('job')->name('cutting-jobs.cutters');
    Route::get('cutting-jobs/{job}/files/{document}', [CuttingJobController::class, 'download'])->whereNumber(['job', 'document'])->middleware('signed')->name('cutting-jobs.files.download');

    Route::post('cutting-jobs/{job}/assignment', [CuttingWorkController::class, 'assign'])->whereNumber('job')->name('cutting-jobs.assign');
    Route::put('cutting-jobs/{job}/quantities', [CuttingWorkController::class, 'quantities'])->whereNumber('job')->name('cutting-jobs.quantities.update');
    Route::patch('cutting-jobs/{job}/checklist/items/{item}', [CuttingWorkController::class, 'checklistItem'])->whereNumber(['job', 'item'])->name('cutting-jobs.checklist.items.update');
    Route::patch('cutting-jobs/{job}/quality-checks/{item}', [CuttingWorkController::class, 'qualityCheck'])->whereNumber(['job', 'item'])->name('cutting-jobs.quality-checks.update');
    Route::post('cutting-jobs/{job}/checklist/client-confirmation', [CuttingWorkController::class, 'confirmChecklist'])->whereNumber('job')->middleware('throttle:uploads')->name('cutting-jobs.checklist.confirm');
    Route::post('cutting-jobs/{job}/complete', [CuttingWorkController::class, 'complete'])->whereNumber('job')->name('cutting-jobs.complete');
    Route::post('cutting-jobs/{job}/issues', [CuttingWorkController::class, 'reportIssue'])->whereNumber('job')->middleware('throttle:uploads')->name('cutting-jobs.issues.store');

    Route::get('cutting-setup', [CuttingSetupController::class, 'index'])->name('cutting-setup.index');
    Route::post('cutting-setup/checklist-templates', [CuttingSetupController::class, 'storeTemplate'])->name('cutting-setup.templates.store');
    Route::patch('cutting-setup/checklist-templates/{template}', [CuttingSetupController::class, 'updateTemplate'])->whereNumber('template')->name('cutting-setup.templates.update');
});
