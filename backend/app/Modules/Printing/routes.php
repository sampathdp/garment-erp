<?php

declare(strict_types=1);

use App\Modules\Printing\Http\Controllers\PrintJobController;
use App\Modules\Printing\Http\Controllers\PrintWorkController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('print-jobs', [PrintJobController::class, 'index'])->name('print-jobs.index');
    Route::get('print-jobs/recipients', [PrintJobController::class, 'recipients'])->name('print-jobs.recipients');
    Route::get('print-jobs/{job}', [PrintJobController::class, 'show'])->whereNumber('job')->name('print-jobs.show');
    Route::get('print-jobs/{job}/job-card', [PrintJobController::class, 'jobCard'])->whereNumber('job')->name('print-jobs.job-card');
    Route::get('print-jobs/{job}/name-list', [PrintJobController::class, 'nameList'])->whereNumber('job')->name('print-jobs.name-list');
    Route::get('print-jobs/{job}/documents/{document}/print', [PrintJobController::class, 'print'])->whereNumber('job')->whereIn('document', ['job-card', 'name-list'])->middleware('throttle:exports')->name('print-jobs.documents.print');
    Route::get('print-jobs/{job}/files/{document}', [PrintJobController::class, 'download'])->whereNumber(['job', 'document'])->middleware('signed')->name('print-jobs.documents.download');

    Route::post('print-jobs/{job}/send', [PrintWorkController::class, 'send'])->whereNumber('job')->name('print-jobs.send');
    Route::post('print-jobs/{job}/samples', [PrintWorkController::class, 'uploadSample'])->whereNumber('job')->middleware('throttle:uploads')->name('print-jobs.samples.store');
    Route::post('print-jobs/{job}/samples/approve', [PrintWorkController::class, 'approveSample'])->whereNumber('job')->name('print-jobs.samples.approve');
    Route::post('print-jobs/{job}/samples/reject', [PrintWorkController::class, 'rejectSample'])->whereNumber('job')->name('print-jobs.samples.reject');
    Route::post('print-jobs/{job}/physical-printing/start', [PrintWorkController::class, 'startPrinting'])->whereNumber('job')->name('print-jobs.printing.start');
    Route::post('print-jobs/{job}/physical-printing/complete', [PrintWorkController::class, 'completePrinting'])->whereNumber('job')->name('print-jobs.printing.complete');
    Route::post('print-jobs/{job}/gate-passes', [PrintWorkController::class, 'recordGatePass'])->whereNumber('job')->middleware('throttle:uploads')->name('print-jobs.gate-passes.store');
});
