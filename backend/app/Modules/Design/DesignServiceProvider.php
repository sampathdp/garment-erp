<?php

declare(strict_types=1);

namespace App\Modules\Design;

use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Policies\DesignTaskPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class DesignServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(DesignTask::class, DesignTaskPolicy::class);
    }
}
