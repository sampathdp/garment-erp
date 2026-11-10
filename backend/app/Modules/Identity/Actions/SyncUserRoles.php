<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccessGuard;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a user's roles. The administrator must hold every permission of each role that is
 * added or removed (no privilege escalation). Permissions are evaluated on every request,
 * so the change applies to the user's next request without signing them out.
 */
final class SyncUserRoles
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessGuard $guard,
    ) {}

    /** @param list<string> $roleNames */
    public function handle(User $actor, User $user, array $roleNames, bool $audit = true): void
    {
        $before = $user->getRoleNames()->sort()->values()->all();
        $after = collect($roleNames)->unique()->sort()->values()->all();
        $changed = array_values(array_unique([...array_diff($after, $before), ...array_diff($before, $after)]));

        if ($changed === []) {
            return;
        }

        $this->guard->assertCanGrantRoles($actor, Role::query()->where('guard_name', 'web')->whereIn('name', $changed)->get());

        DB::transaction(function () use ($actor, $user, $after, $before, $audit): void {
            $user->syncRoles($after);
            if ($audit) {
                $this->audit->record('users.roles_changed', $user, ['before' => $before, 'after' => $after], actor: $actor);
            }
        });
    }
}
