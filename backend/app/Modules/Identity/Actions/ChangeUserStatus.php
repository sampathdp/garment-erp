<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates an account. Deactivation ends every session of the user
 * immediately (EnsureSessionIsValid also rejects inactive users on every request).
 */
final class ChangeUserStatus
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(User $actor, User $user, bool $active, ?string $reason): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        return DB::transaction(function () use ($actor, $user, $active, $reason): User {
            $user->forceFill(['is_active' => $active, 'deactivated_at' => $active ? null : now()]);

            if ($active) {
                $user->save();
            } else {
                $this->revokeSessions->handle($user); // saves
            }

            $this->audit->record($active ? 'users.activated' : 'users.deactivated', $user, ['reason' => $reason], actor: $actor);

            return $user;
        });
    }
}
