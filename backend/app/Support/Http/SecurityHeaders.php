<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for API responses (SECURITY_STANDARDS.md §11).
 * The API only serves JSON, so the CSP forbids everything.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('api/*')) {
            // API responses carry personal/business data: never store in shared or browser caches.
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
