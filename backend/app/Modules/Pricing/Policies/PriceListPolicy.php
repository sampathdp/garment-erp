<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Pricing\Models\PriceList;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Page-specific price lists: page scope (404) → permission (403). */
final class PriceListPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRICING_VIEW);
    }

    public function view(User $user, PriceList $list): Response
    {
        return $this->allowScoped($user, $list, Permissions::PRICING_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PRICING_MANAGE);
    }

    public function update(User $user, PriceList $list): Response
    {
        return $this->decide($list->isVisibleTo($user) && $user->can(Permissions::PRICING_VIEW), $user->can(Permissions::PRICING_MANAGE));
    }
}
