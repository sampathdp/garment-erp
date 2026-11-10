<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Records whether a real authorization check happened during the current request.
 * Data-scope permissions (scope.*) do not count: they filter data, they do not authorize an action.
 */
final class AuthorizationTracker
{
    private bool $checked = false;

    public function mark(string $ability): void
    {
        if (! str_starts_with($ability, 'scope.')) {
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
}
