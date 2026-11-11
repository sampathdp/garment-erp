<?php

declare(strict_types=1);

use App\Modules\Sewing\Http\Controllers\SewingJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('sewing-jobs', [SewingJobController::class, 'index'])->name('sewing-jobs.index');
    Route::get('sewing-jobs/{job}', [SewingJobController::class, 'show'])->whereNumber('job')->name('sewing-jobs.show');
    Route::post('sewing-jobs/{job}/start', [SewingJobController::class, 'start'])->whereNumber('job')->name('sewing-jobs.start');
    Route::post('sewing-jobs/{job}/complete', [SewingJobController::class, 'complete'])->whereNumber('job')->name('sewing-jobs.complete');
});
