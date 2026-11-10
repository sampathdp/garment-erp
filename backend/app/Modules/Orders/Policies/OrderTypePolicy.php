<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;

/** Order types are company-wide reference data, configured with the product catalogue. */
final class OrderTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permissions::PRICING_VIEW, Permissions::ORDERS_VIEW, Permissions::ORDERS_CREATE]);
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
