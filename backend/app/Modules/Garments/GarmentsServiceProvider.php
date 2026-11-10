<?php

declare(strict_types=1);

namespace App\Modules\Garments;

use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Policies\GarmentListPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class GarmentsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(GarmentList::class, GarmentListPolicy::class);

        // Master data (company-wide): read by anyone working on lists or pricing; changed by catalogue managers.
        Gate::define('garment-setup.view', fn (User $user): bool => $user->canAny([Permissions::PRICING_VIEW, Permissions::GARMENT_LISTS_VIEW, Permissions::PRICING_MANAGE]));
        Gate::define('garment-setup.manage', fn (User $user): bool => $user->can(Permissions::PRICING_MANAGE));
    }
}
