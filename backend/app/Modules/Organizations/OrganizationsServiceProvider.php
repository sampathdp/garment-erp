<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Modules\Organizations\Policies\BranchPolicy;
use App\Modules\Organizations\Policies\PagePolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class OrganizationsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::define('workflow-settings.manage', fn (User $user): bool => $user->can(Permissions::SETTINGS_MANAGE));
    }
}
