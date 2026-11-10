<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Authorization\AuthorizationTracker;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails any authenticated request whose controller never performed an authorization
 * check (policy, Gate, $this->authorize(), FormRequest::authorize() using Gate).
 *
 * Local/testing: throws, so a missing check fails tests immediately.
 * Production: logs critical and fails closed with 403.
 *
 * Routes that legitimately act only on the caller's own data (me, logout) opt out with
 * ->withoutMiddleware(RequireAuthorization::class) and are listed in RouteAuthorizationTest.
 */
final class RequireAuthorization
{
    public function __construct(private readonly AuthorizationTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tracker->reset();

        $response = $next($request);

        if ($response->getStatusCode() >= 400 || $this->tracker->wasChecked()) {
            return $response;
        }

        $route = $request->route()?->getName() ?? $request->path();

        if (! app()->isProduction()) {
            throw new LogicException("Route [{$route}] completed without an authorization check.");
        }

        Log::critical('Route completed without an authorization check.', ['route' => $route]);

        return new JsonResponse([
            'message' => 'This action is unauthorized.',
            'code' => 'forbidden',
            'request_id' => RequestId::current($request),
        ], 403);
    }
}
