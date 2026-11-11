<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Never name a Gate ability after a Spatie permission (it recurses).
        Gate::define('dashboard-view', fn (User $user): bool => $user->is_active);
        Gate::define('reports-access', fn (User $user): bool => $user->can(Permissions::REPORTS_VIEW));
    }
}
