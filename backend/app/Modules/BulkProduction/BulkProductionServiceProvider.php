<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Policies\BulkJobPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class BulkProductionServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(BulkJob::class, BulkJobPolicy::class);
        // Never name a Gate ability after a Spatie permission (it recurses): bulk-setup.manage ≠ bulk.configure.
        Gate::define('bulk-setup.manage', fn (User $user): bool => $user->can(Permissions::BULK_CONFIGURE));
    }
}
