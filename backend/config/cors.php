<?php

declare(strict_types=1);

// Exact frontend origin(s) only. Credentials (session cookie) require a non-wildcard origin.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => true,
];
