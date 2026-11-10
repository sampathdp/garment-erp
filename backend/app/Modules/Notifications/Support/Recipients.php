<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Resolves notification recipients by PERMISSION and data scope (never by role name), so a user
 * is only told about records they are allowed to open.
 */
final class Recipients
{
    /**
     * Active users holding $permission (directly or through a role) whose scope covers the branch and page.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, User>
     */
    public function withPermission(string $permission, int $branchId, int $pageId, array $exclude = []): Collection
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return new Collection;
        }

        return User::query()
            ->permission($permission)
            ->where('is_active', true)
            ->whereNotIn('users.id', $exclude)
            ->get()
            ->filter(fn (User $u): bool => $u->canAccessBranch($branchId) && $u->canAccessPage($pageId))
            ->values();
    }

    /**
     * Page Owners of the page (page_user.is_owner).
     *
     * @param  list<int>  $exclude
     * @return Collection<int, User>
     */
    public function pageOwners(int $pageId, array $exclude = []): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotIn('users.id', $exclude)
            ->whereHas('pages', fn (Builder $q) => $q->where('pages.id', $pageId)->where('page_user.is_owner', true))
            ->get();
    }

    /** @return Collection<int, User> */
    public function users(int ...$ids): Collection
    {
        return User::query()->whereKey(array_values(array_unique($ids)))->where('is_active', true)->get();
    }
}
