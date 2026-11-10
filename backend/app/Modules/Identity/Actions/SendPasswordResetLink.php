<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Sends a single-use password reset (or account setup) link.
 *
 * Self-service: always answers the same way (no account enumeration) and sends nothing for
 * unknown or inactive accounts. Administrator-initiated: the administrator never sees or sets
 * the password; optionally every existing session of the user is ended at the same time.
 */
final class SendPasswordResetLink
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function forEmail(string $email): void
    {
        $this->broker()->sendResetLink(['email' => $email], function (User $user, string $token): void {
            if (! $user->is_active) {
                $this->audit->record('auth.password_reset_requested_inactive', $user, actor: $user);

                return;
            }

            $user->notify(new ResetPasswordNotification($token));
            $this->audit->record('auth.password_reset_requested', $user, actor: $user);
        });
    }

    public function byAdministrator(User $actor, User $user, bool $signOut, bool $invitation = false): void
    {
        DB::transaction(function () use ($actor, $user, $signOut, $invitation): void {
            if ($signOut) {
                $this->revokeSessions->handle($user);
            }

            $this->audit->record($invitation ? 'users.invitation_sent' : 'users.password_reset_sent', $user, ['sessions_revoked' => $signOut], actor: $actor);
        });

        // createToken() replaces any earlier token of this user, so older links stop working.
        $user->notify(new ResetPasswordNotification($this->broker()->createToken($user), $invitation));
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        return $broker;
    }
}
