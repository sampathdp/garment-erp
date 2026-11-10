<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for a module. Loads the module's routes.php (if present) under
 * /api/v1 with the `api` middleware group. Route files declare their own
 * auth middleware explicitly (public routes are the exception, not the default).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routes = dirname((string) (new ReflectionClass(static::class))->getFileName()).'/routes.php';

        if (is_file($routes) && ! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group($routes);
        }

        $this->bootModule();
    }

    protected function bootModule(): void {}
}
