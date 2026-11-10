<?php

declare(strict_types=1);

use App\Modules\Design\Http\Controllers\DesignTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('authenticated')->group(function (): void {
    Route::get('design-tasks', [DesignTaskController::class, 'index'])->name('design-tasks.index');
    Route::get('design-tasks/designers', [DesignTaskController::class, 'designers'])->name('design-tasks.designers');
    Route::get('design-tasks/{task}', [DesignTaskController::class, 'show'])->whereNumber('task')->name('design-tasks.show');
    Route::post('design-tasks/{task}/assign', [DesignTaskController::class, 'assign'])->whereNumber('task')->name('design-tasks.assign');
    Route::post('design-tasks/{task}/versions', [DesignTaskController::class, 'submitVersion'])->whereNumber('task')->middleware('throttle:uploads')->name('design-tasks.versions.store');
    Route::post('design-tasks/{task}/corrections', [DesignTaskController::class, 'requestCorrection'])->whereNumber('task')->name('design-tasks.corrections.store');
    Route::post('design-tasks/{task}/approve', [DesignTaskController::class, 'approve'])->whereNumber('task')->name('design-tasks.approve');
    Route::post('design-tasks/{task}/final', [DesignTaskController::class, 'submitFinal'])->whereNumber('task')->middleware('throttle:uploads')->name('design-tasks.final.store');
    Route::get('design-tasks/{task}/files/{file}', [DesignTaskController::class, 'download'])->whereNumber(['task', 'file'])->middleware('signed')->name('design-tasks.files.download');
});
