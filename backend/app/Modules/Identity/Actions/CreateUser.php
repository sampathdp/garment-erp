<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccessGuard;
use Illuminate\Support\Facades\DB;

/**
 * Creates a staff account with the password entered by the administrator (no email is sent).
 * The password is hashed by the model cast and never logged or audited.
 */
final class CreateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessGuard $guard,
        private readonly SyncUserRoles $syncRoles,
        private readonly SyncUserAssignments $syncAssignments,
    ) {}

    /**
     * @param  array{name: string, email: string, phone_e164?: string|null}  $details
     * @param  list<string>  $roles
     * @param  list<int>  $branchIds
     * @param  list<int>  $pageIds
     * @param  list<int>  $ownerPageIds
     */
    public function handle(User $actor, array $details, #[\SensitiveParameter] string $password, array $roles, array $branchIds, array $pageIds, array $ownerPageIds): User
    {
        // Checked before anything is written, so a rejected request leaves no partial account.
        $this->guard->assertCanGrantRoles($actor, Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get());
        $this->guard->assertScopeAssignable($actor, $branchIds, $pageIds);

        return DB::transaction(function () use ($actor, $details, $password, $roles, $branchIds, $pageIds, $ownerPageIds): User {
            $user = new User($details + ['password' => $password]);
            $user->forceFill(['is_active' => true, 'session_version' => 1])->save();

            $this->syncRoles->handle($actor, $user, $roles, audit: false);
            $this->syncAssignments->handle($actor, $user, $branchIds, $pageIds, $ownerPageIds, audit: false);

            $this->audit->record('users.created', $user, ['after' => [
                'name' => $user->name, 'email' => $user->email, 'roles' => $roles,
                'branch_ids' => $branchIds, 'page_ids' => $pageIds, 'owner_page_ids' => $ownerPageIds,
                'password_set_by_administrator' => true,
            ]], actor: $actor);

            return $user;
        });
    }
}
