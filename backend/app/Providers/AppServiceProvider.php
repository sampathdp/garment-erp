<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Authorization\AuthorizationTracker;
use App\Support\Database\MorphMap;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tracker per request (also correct under long-running workers).
        $this->app->scoped(AuthorizationTracker::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureQueryMonitoring();
        $this->configureSecurityDefaults();
    }

    private function configureModels(): void
    {
        // Outside production: lazy loading (N+1), silently discarded attributes and
        // missing-attribute access throw, so they are caught in development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable aliases in polymorphic columns (audit_logs.subject_type, notifications, Spatie pivots).
        Relation::enforceMorphMap(MorphMap::map());
    }

    private function configureAuthorization(): void
    {
        Gate::after(function ($user, string $ability): void {
            $this->app->make(AuthorizationTracker::class)->mark($ability);
        });
    }

    private function configureQueryMonitoring(): void
    {
        $slowMs = (int) config('database.slow_query_ms', 200);

        DB::listen(static function (QueryExecuted $query) use ($slowMs): void {
            if ($query->time >= $slowMs) {
                Log::channel('slow_queries')->warning('Slow query', [
                    'ms' => $query->time,
                    'sql' => $query->sql, // bindings deliberately omitted (may contain personal data)
                    'connection' => $query->connectionName,
                ]);
            }
        });

        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan((int) config('database.request_query_budget_ms', 1000), static function (Connection $connection): void {
                Log::channel('slow_queries')->warning('Request exceeded cumulative query time budget', [
                    'connection' => $connection->getName(),
                    'total_ms' => $connection->totalQueryDuration(),
                ]);
            });
        }
    }

    private function configureSecurityDefaults(): void
    {
        // SECURITY_STANDARDS.md §1: at least 10 characters, letters and numbers, not in breach lists.
        Password::defaults(function () {
            $rule = Password::min(10)->max(200)->letters()->numbers();

            return config('api.passwords.check_breached') ? $rule->uncompromised() : $rule;
        });

        $proxies = config('app.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
