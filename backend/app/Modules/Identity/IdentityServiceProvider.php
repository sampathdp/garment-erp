<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\RolePolicy;
use App\Modules\Identity\Policies\UserPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // 5 attempts/min per email+IP, plus 20/min per IP against credential stuffing across many emails.
        // LoginLockout adds an exponential lockout per email+IP on top.
        RateLimiter::for('login', static function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute((int) config('api.rate_limits.login_per_email', 5))->by('login:'.sha1($email.'|'.$request->ip())),
                Limit::perMinute((int) config('api.rate_limits.login_per_ip', 20))->by('login-ip:'.$request->ip()),
            ];
        });

        // Forgot/reset password: per IP (the broker also allows one email per 60 s per account).
        RateLimiter::for('password-reset', static fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limits.password_reset_per_ip', 5))
            ->by('password-reset:'.$request->ip()));

        // Password change, profile, user creation and admin-sent reset links.
        RateLimiter::for('sensitive', static fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limits.sensitive_per_user', 6))
            ->by('sensitive:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute((int) config('api.rate_limits.api_per_user', 120))->by('user:'.$user->id)
                : Limit::perMinute((int) config('api.rate_limits.api_per_ip', 60))->by('ip:'.$request->ip());
        });

        RateLimiter::for('uploads', static fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limits.uploads_per_user', 20))
            ->by('uploads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('exports', static fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limits.exports_per_user', 10))
            ->by('exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
