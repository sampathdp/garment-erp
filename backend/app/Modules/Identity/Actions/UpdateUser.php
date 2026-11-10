<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Edits a user's name, email and phone. An email change ends the user's sessions. */
final class UpdateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    /** @param array<string, string|null> $details name / email / phone_e164 */
    public function handle(User $actor, User $user, array $details): User
    {
        return DB::transaction(function () use ($actor, $user, $details): User {
            $before = $user->only(array_keys($details));
            $user->fill($details);
            $dirty = $user->getDirty();

            if ($dirty === []) {
                return $user;
            }

            $emailChanged = array_key_exists('email', $dirty);
            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null]);
                $this->revokeSessions->handle($user); // saves
            } else {
                $user->save();
            }

            $this->audit->record('users.updated', $user, [
                'before' => array_intersect_key($before, $dirty),
                'after' => $dirty,
                'sessions_revoked' => $emailChanged,
            ], actor: $actor);

            return $user;
        });
    }
}
