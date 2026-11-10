<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\EnsureSessionIsValid;
use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Http\LimitRequestSize;
use App\Support\Http\RequestId;
use App\Support\Http\RequireAuthorization;
use App\Support\Http\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: requests from SANCTUM_STATEFUL_DOMAINS get session + CSRF protection.
        $middleware->statefulApi();

        // Global: request id first (so every log line and error carries it), then headers and size limits.
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(LimitRequestSize::class);

        $middleware->throttleApi('api');

        // Every protected route uses this group: authenticate, validate session/account, require an authorization check.
        $middleware->group('authenticated', [
            'auth:sanctum',
            EnsureSessionIsValid::class,
            RequireAuthorization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
