<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * An administrator sets a new password for another user (no email). Policy: UserPolicy::administer
 * (scope, permission subset, never the own account). All of the user's sessions are ended by
 * default, and any outstanding reset link stops working. The password is never logged or audited.
 */
final class SetUserPassword
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(User $actor, User $user, #[\SensitiveParameter] string $password, bool $signOut): void
    {
        DB::transaction(function () use ($actor, $user, $password, $signOut): void {
            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            if ($signOut) {
                $this->revokeSessions->handle($user);
            }
            $this->audit->record('users.password_set', $user, ['after' => ['sessions_revoked' => $signOut]], actor: $actor);
        });

        Password::broker()->getRepository()->delete($user);
    }
}
