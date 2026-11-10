<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Changes the signed-in user's password after re-checking the current one.
 * Every other session of the user is ended; the caller re-stamps the current session.
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit->record('auth.password_change_failed', $user, actor: $user);

            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        DB::transaction(function () use ($user, $new): void {
            $user->forceFill(['password' => $new, 'password_changed_at' => now()]);
            $this->revokeSessions->handle($user);
            $this->audit->record('auth.password_changed', $user, actor: $user);
        });
    }
}
