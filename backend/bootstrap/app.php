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

$app = Application::configure(basePath: dirname(__DIR__))
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

/*
| Shared hosting: backend/ sits inside the web root, so the production settings file is kept outside it,
| at <home>/envs/garment-erp.env (docs/DEPLOYMENT.md). The home directory is two levels above backend/
| when the subdomain root is <home>/<subdomain>/, three when it is <home>/public_html/<subdomain>/.
| Without that file Laravel reads backend/.env as usual (local development, CI).
*/
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4)] as $home) {
    if (is_file($home.'/envs/garment-erp.env')) {
        $app->useEnvironmentPath($home.'/envs')->loadEnvironmentFrom('garment-erp.env');
        break;
    }
}

return $app;
