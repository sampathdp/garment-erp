<?php

declare(strict_types=1);

namespace App\Modules\Sewing;

use App\Modules\Sewing\Models\SewingJob;
use App\Modules\Sewing\Policies\SewingJobPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class SewingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(SewingJob::class, SewingJobPolicy::class);
    }
}
