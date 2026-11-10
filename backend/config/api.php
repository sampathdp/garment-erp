<?php

declare(strict_types=1);

return [
    'version' => 'v1',

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    // Request body limits (bytes). Uploads are additionally limited per DocumentCategory.
    'max_json_body_bytes' => (int) env('API_MAX_JSON_BODY_BYTES', 1024 * 1024),
    'max_upload_body_bytes' => (int) env('API_MAX_UPLOAD_BODY_BYTES', 30 * 1024 * 1024),

    'rate_limits' => [
        'login_per_email' => 5,
        'login_per_ip' => 20,
        'api_per_user' => 120,
        'api_per_ip' => 60,
        'uploads_per_user' => 20,
        'exports_per_user' => 10,
        'password_reset_per_ip' => 5,
        'sensitive_per_user' => 6,
    ],

    // Exponential login lockout per email + IP (App\Modules\Identity\Support\LoginLockout).
    'login_lockout' => [
        'threshold' => 5,
        'base_seconds' => 60,
        'max_seconds' => 3600,
    ],

    // Breached-password check (HaveIBeenPwned k-anonymity API). Disabled in tests (no network).
    'passwords' => [
        'check_breached' => (bool) env('PASSWORD_CHECK_BREACHED', true),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
