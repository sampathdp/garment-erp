<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Http\Controllers\BulkJobController;
use App\Modules\BulkProduction\Http\Controllers\BulkSetupController;
use App\Modules\BulkProduction\Http\Controllers\BulkWorkController;
use App\Modules\BulkProduction\Http\Controllers\ProductionIssueController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('bulk-jobs', [BulkJobController::class, 'index'])->name('bulk-jobs.index');
    Route::get('bulk-jobs/assignees', [BulkJobController::class, 'assignees'])->name('bulk-jobs.assignees');
    Route::get('bulk-jobs/{job}', [BulkJobController::class, 'show'])->whereNumber('job')->name('bulk-jobs.show');
    Route::get('bulk-jobs/{job}/job-card', [BulkJobController::class, 'jobCard'])->whereNumber('job')->name('bulk-jobs.job-card');
    Route::get('bulk-jobs/{job}/garment-list', [BulkJobController::class, 'garmentList'])->whereNumber('job')->name('bulk-jobs.garment-list');
    Route::get('bulk-jobs/{job}/garment-list/export', [BulkJobController::class, 'exportGarmentList'])->whereNumber('job')->middleware('throttle:exports')->name('bulk-jobs.garment-list.export');
    Route::get('bulk-jobs/{job}/documents/{document}', [BulkJobController::class, 'download'])->whereNumber(['job', 'document'])->middleware('signed')->name('bulk-jobs.documents.download');
    Route::post('bulk-jobs/{job}/assignment', [BulkJobController::class, 'assign'])->whereNumber('job')->name('bulk-jobs.assign');

    Route::put('bulk-jobs/{job}/measurements', [BulkWorkController::class, 'measurements'])->whereNumber('job')->name('bulk-jobs.measurements.update');
    Route::post('bulk-jobs/{job}/measurements/corrections', [BulkWorkController::class, 'correctMeasurements'])->whereNumber('job')->name('bulk-jobs.measurements.correct');
    Route::patch('bulk-jobs/{job}/checklist/items/{item}', [BulkWorkController::class, 'checklistItem'])->whereNumber(['job', 'item'])->name('bulk-jobs.checklist.items.update');
    Route::post('bulk-jobs/{job}/checklist/client-confirmation', [BulkWorkController::class, 'confirmChecklist'])->whereNumber('job')->middleware('throttle:uploads')->name('bulk-jobs.checklist.confirm');
    Route::post('bulk-jobs/{job}/versions', [BulkWorkController::class, 'submitForReview'])->whereNumber('job')->middleware('throttle:uploads')->name('bulk-jobs.versions.store');
    Route::post('bulk-jobs/{job}/corrections', [BulkWorkController::class, 'requestCorrection'])->whereNumber('job')->name('bulk-jobs.corrections.store');
    Route::post('bulk-jobs/{job}/approve', [BulkWorkController::class, 'approve'])->whereNumber('job')->name('bulk-jobs.approve');
    Route::post('bulk-jobs/{job}/final', [BulkWorkController::class, 'submitFinal'])->whereNumber('job')->middleware('throttle:uploads')->name('bulk-jobs.final.store');
    Route::post('bulk-jobs/{job}/external/evidence', [BulkWorkController::class, 'addEvidence'])->whereNumber('job')->middleware('throttle:uploads')->name('bulk-jobs.external.evidence');
    Route::post('bulk-jobs/{job}/external/complete', [BulkWorkController::class, 'completeExternal'])->whereNumber('job')->name('bulk-jobs.external.complete');

    Route::get('bulk-setup', [BulkSetupController::class, 'index'])->name('bulk-setup.index');
    Route::post('bulk-setup/measurement-fields', [BulkSetupController::class, 'storeField'])->name('bulk-setup.fields.store');
    Route::patch('bulk-setup/measurement-fields/{field}', [BulkSetupController::class, 'updateField'])->whereNumber('field')->name('bulk-setup.fields.update');
    Route::post('bulk-setup/checklist-templates', [BulkSetupController::class, 'storeTemplate'])->name('bulk-setup.templates.store');
    Route::patch('bulk-setup/checklist-templates/{template}', [BulkSetupController::class, 'updateTemplate'])->whereNumber('template')->name('bulk-setup.templates.update');
    Route::post('bulk-setup/suppliers', [BulkSetupController::class, 'storeSupplier'])->name('bulk-setup.suppliers.store');
    Route::patch('bulk-setup/suppliers/{supplier}', [BulkSetupController::class, 'updateSupplier'])->whereNumber('supplier')->name('bulk-setup.suppliers.update');

    Route::get('production-issues', [ProductionIssueController::class, 'index'])->name('production-issues.index');
    Route::post('production-issues', [ProductionIssueController::class, 'store'])->middleware('throttle:uploads')->name('production-issues.store');
    Route::get('production-issues/{issue}', [ProductionIssueController::class, 'show'])->whereNumber('issue')->name('production-issues.show');
    Route::patch('production-issues/{issue}', [ProductionIssueController::class, 'update'])->whereNumber('issue')->name('production-issues.update');
    Route::post('production-issues/{issue}/transitions', [ProductionIssueController::class, 'transition'])->whereNumber('issue')->name('production-issues.transitions.store');
    Route::get('production-issues/{issue}/assignees', [ProductionIssueController::class, 'assignees'])->whereNumber('issue')->name('production-issues.assignees');
    Route::get('production-issues/{issue}/image', [ProductionIssueController::class, 'image'])->whereNumber('issue')->middleware('signed')->name('production-issues.image');
});
