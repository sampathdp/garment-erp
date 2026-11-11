<?php

declare(strict_types=1);

namespace App\Modules\Cutting;

use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Policies\CuttingJobPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class CuttingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(CuttingJob::class, CuttingJobPolicy::class);
        Gate::define('cutting-setup.manage', fn (User $user): bool => $user->can(Permissions::CUTTING_CONFIGURE));
    }
}
