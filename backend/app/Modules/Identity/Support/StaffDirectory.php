<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/** Finds active staff who hold a permission (responsible designers, bulk designers, ...). */
final class StaffDirectory
{
    /** The user, if active and holding the permission; otherwise null. */
    public function eligible(?int $userId, string $permission): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        return $user !== null && $user->can($permission) ? $user : null;
    }

    /** @return Collection<int, User> */
    public function withPermission(string $permission): Collection
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return new Collection;
        }

        return User::query()->permission($permission)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    }
}
