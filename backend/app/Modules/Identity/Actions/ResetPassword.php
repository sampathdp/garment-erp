<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Completes a reset with a single-use token (hashed in password_reset_tokens, 60 min expiry).
 * Ends all sessions of the user. Does not sign the user in: they log in with the new password.
 */
final class ResetPassword
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password],
            function (User $user, string $newPassword): void {
                DB::transaction(function () use ($user, $newPassword): void {
                    $user->forceFill(['password' => $newPassword, 'password_changed_at' => now()]);
                    $this->revokeSessions->handle($user); // saves the user
                    $this->audit->record('auth.password_reset', $user, actor: $user);
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Same answer for unknown email, wrong token and expired token.
            throw new ApiException(422, 'invalid_reset_token', 'This password reset link is invalid or has expired.', [
                'token' => ['This password reset link is invalid or has expired. Request a new one.'],
            ]);
        }
    }
}
