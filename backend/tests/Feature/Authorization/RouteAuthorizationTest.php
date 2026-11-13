<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Support\Http\RequireAuthorization;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/** Routes reachable without authentication. Adding one requires a security review. */
const PUBLIC_API_ROUTES = ['health', 'auth.login', 'auth.forgot-password', 'auth.reset-password'];

/** Authenticated routes that act only on the caller's own data (no record-level policy). */
const AUTHORIZATION_EXEMPT_ROUTES = [
    'auth.logout', 'auth.me', 'auth.profile', 'auth.password',
    'notifications.index', 'notifications.unread-count', 'notifications.mark-all-read', 'notifications.poll', 'notifications.read', 'notifications.unread',
];

function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/'))
        ->values()
        ->all();
}

it('names every API route', function () {
    foreach (apiRoutes() as $route) {
        expect($route->getName())->not->toBeNull("Route [{$route->uri()}] has no name");
    }
});

it('requires authentication on every non-public API route', function () {
    foreach (apiRoutes() as $route) {
        if (in_array($route->getName(), PUBLIC_API_ROUTES, true)) {
            continue;
        }

        $authenticated = collect(Route::gatherRouteMiddleware($route))
            ->contains(fn ($m) => is_string($m) && str_ends_with($m, 'Authenticate:sanctum'));
        expect($authenticated)->toBeTrue("Route [{$route->getName()}] is not authenticated");
    }
});

it('requires an authorization check on every authenticated route except documented exemptions', function () {
    foreach (apiRoutes() as $route) {
        $name = $route->getName();
        if (in_array($name, PUBLIC_API_ROUTES, true)) {
            continue;
        }

        $hasGuard = in_array(RequireAuthorization::class, Route::gatherRouteMiddleware($route), true);

        in_array($name, AUTHORIZATION_EXEMPT_ROUTES, true)
            ? expect($hasGuard)->toBeFalse("Exempt route [{$name}] unexpectedly has RequireAuthorization")
            : expect($hasGuard)->toBeTrue("Route [{$name}] is missing RequireAuthorization");
    }
});

it('fails a protected route that never performs an authorization check', function () {
    Route::middleware(['api', 'authenticated'])->get('/api/v1/_test/unchecked', fn () => ['secret' => 'data'])->name('test.unchecked');

    $this->withoutExceptionHandling();
    $this->signedInAs(User::factory()->create())->getJson('/api/v1/_test/unchecked');
})->throws(LogicException::class, 'completed without an authorization check');

it('does not count incidental permission lookups as an authorization check', function () {
    // A controller that only reads permissions (as a Resource's `abilities` or a list scope does)
    // without calling a policy or Gate ability must still fail the safety net.
    Route::middleware(['api', 'authenticated'])->get('/api/v1/_test/permission-only', function () {
        $user = request()->user();

        return ['can_view' => $user->can('orders.view'), 'all_pages' => $user->can('scope.all_pages')];
    })->name('test.permission-only');

    $this->withoutExceptionHandling();
    $this->signedInAs(User::factory()->create())->getJson('/api/v1/_test/permission-only');
})->throws(LogicException::class, 'completed without an authorization check');

it('fails closed with 403 in production when a check is missing', function () {
    Route::middleware(['api', 'authenticated'])->get('/api/v1/_test/unchecked-prod', fn () => ['secret' => 'data'])->name('test.unchecked-prod');
    app()->detectEnvironment(fn () => 'production');

    $this->signedInAs(User::factory()->create())->getJson('/api/v1/_test/unchecked-prod')
        ->assertForbidden()
        ->assertJsonMissing(['secret' => 'data']);
});
