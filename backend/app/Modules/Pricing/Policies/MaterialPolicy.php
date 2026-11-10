<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;

/** Fabric types are company-wide reference data. */
final class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permissions::PRICING_VIEW, Permissions::ORDERS_VIEW, Permissions::DESIGNS_INSTRUCT]);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PRICING_MANAGE);
    }

    public function update(User $user): bool
    {
        return $user->can(Permissions::PRICING_MANAGE);
    }
}
