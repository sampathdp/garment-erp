<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every authenticated request:
 *  - deactivated users are logged out immediately (not only at next login);
 *  - sessions older than the absolute lifetime are ended, regardless of activity;
 *  - sessions issued before the user's last session revocation (password change/reset,
 *    deactivation, admin sign-out) are ended: the session stores users.session_version at login.
 */
final class EnsureSessionIsValid
{
    public const AUTHENTICATED_AT = 'auth.authenticated_at';

    public const SESSION_VERSION = 'auth.session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            $this->endSession($request);

            throw ApiException::forbidden('account_inactive', 'Your account is inactive. Contact an administrator.');
        }

        if ($request->hasSession()) {
            $session = $request->session();
            $authenticatedAt = (int) $session->get(self::AUTHENTICATED_AT, 0);
            $maxSeconds = (int) config('session.absolute_lifetime', 720) * 60;

            if ($authenticatedAt === 0 || (time() - $authenticatedAt) > $maxSeconds) {
                $this->endSession($request);

                throw new AuthenticationException('Session expired.');
            }

            if ($user instanceof User && (int) $session->get(self::SESSION_VERSION, 0) !== $user->session_version) {
                $this->endSession($request);

                throw new AuthenticationException('Session revoked.');
            }
        }

        return $next($request);
    }

    /** Marks the current session as authenticated now, for the user's current session version. */
    public static function stamp(Request $request, User $user): void
    {
        $request->session()->put(self::AUTHENTICATED_AT, time());
        $request->session()->put(self::SESSION_VERSION, $user->session_version);
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
