<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Modules\Identity\Support\Permissions;

/**
 * Records whether a real authorization check happened during the current request.
 *
 * Only policy abilities and named Gates count (`view`, `approve`, `workflow-settings.manage` ...).
 * Plain permission lookups do not: `$user->can('orders.view')` also runs through the Gate (Spatie),
 * and it happens incidentally all over the request — inside list scopes, inside other policies and
 * when a Resource computes its `abilities` object. Counting those would let a controller that
 * forgot `Gate::authorize()` pass the RequireAuthorization safety net.
 */
final class AuthorizationTracker
{
    private bool $checked = false;

    /** @var array<string, true>|null */
    private static ?array $permissionNames = null;

    public function mark(string $ability): void
    {
        if (! self::isPermissionName($ability)) {
            $this->checked = true;
        }
    }

    public function reset(): void
    {
        $this->checked = false;
    }

    public function wasChecked(): bool
    {
        return $this->checked;
    }

    private static function isPermissionName(string $ability): bool
    {
        self::$permissionNames ??= array_fill_keys(Permissions::all(), true);

        return isset(self::$permissionNames[$ability]) || str_starts_with($ability, 'scope.');
    }
}
