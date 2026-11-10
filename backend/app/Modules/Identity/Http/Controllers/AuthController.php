<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Actions\ChangeOwnPassword;
use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Actions\SendPasswordResetLink;
use App\Modules\Identity\Http\Middleware\EnsureSessionIsValid;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\CurrentUserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\LoginLockout;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA (stateful cookie) authentication. No API tokens are issued to browsers.
 */
final class AuthController
{
    private const INVALID_CREDENTIALS = 'These credentials do not match our records.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoginLockout $lockout,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            // The request did not come from a configured stateful frontend (SANCTUM_STATEFUL_DOMAINS).
            throw ApiException::forbidden('stateful_origin_required', 'Login is only available from the application.');
        }

        $email = $request->email();
        $ip = $request->ip();

        if (($wait = $this->lockout->secondsRemaining($email, $ip)) > 0) {
            throw new ApiException(429, 'login_locked', "Too many failed sign-in attempts. Try again in {$wait} seconds.");
        }

        // SessionGuard::attempt runs inside a Timebox, so unknown and known emails take the same time.
        $credentials = ['email' => $email, 'password' => (string) $request->validated('password')];

        if (! Auth::guard('web')->attempt($credentials, (bool) $request->validated('remember', false))) {
            $this->lockout->recordFailure($email, $ip);
            $this->audit->record('auth.login_failed', changes: ['email' => $email]);

            throw ValidationException::withMessages(['email' => [self::INVALID_CREDENTIALS]]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $this->audit->record('auth.login_blocked_inactive', $user, actor: $user);

            // Same message as a wrong password, so account state is not revealed.
            throw ValidationException::withMessages(['email' => [self::INVALID_CREDENTIALS]]);
        }

        $this->lockout->clear($email, $ip);

        // Session fixation protection: new session ID after authentication.
        $request->session()->regenerate();
        EnsureSessionIsValid::stamp($request, $user);

        DB::transaction(function () use ($user): void {
            $user->forceFill(['last_login_at' => now()])->save();
            $this->audit->record('auth.login', $user, actor: $user);
        });

        return (new CurrentUserResource($user))->response();
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->audit->record('auth.logout', $user, actor: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }

    public function updateProfile(UpdateProfileRequest $request): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();
        $before = ['name' => $user->name, 'phone_e164' => $user->phone_e164];

        DB::transaction(function () use ($user, $request, $before): void {
            $user->fill($request->profile())->save();
            $this->audit->record('auth.profile_updated', $user, ['before' => $before, 'after' => $request->profile()], actor: $user);
        });

        return new CurrentUserResource($user);
    }

    public function changePassword(ChangePasswordRequest $request, ChangeOwnPassword $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->validated('current_password'), (string) $request->validated('password'));

        // Other sessions are revoked; this one continues with a new ID (privilege/credential change).
        $request->session()->regenerate();
        EnsureSessionIsValid::stamp($request, $user);

        return response()->noContent();
    }

    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetLink $action): JsonResponse
    {
        $action->forEmail($request->email());

        // Identical response whether or not the account exists (no enumeration).
        return new JsonResponse(['message' => 'If an active account exists for this email, a reset link has been sent.'], 202);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPassword $action): JsonResponse
    {
        $action->handle($request->email(), (string) $request->validated('token'), (string) $request->validated('password'));

        return new JsonResponse(['message' => 'Your password has been reset. Sign in with your new password.']);
    }
}
