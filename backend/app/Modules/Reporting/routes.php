<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard.show');
    Route::get('reports', [ReportController::class, 'catalogue'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z\-]+')->middleware('throttle:exports')->name('reports.show');
});
