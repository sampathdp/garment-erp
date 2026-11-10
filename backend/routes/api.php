<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Module routes are registered by each module's service provider under /api/v1
| (see App\Support\Modules\ModuleServiceProvider). Only cross-cutting routes live here.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});
