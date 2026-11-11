<?php

declare(strict_types=1);

namespace App\Modules\Printing;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Policies\PrintJobPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class PrintingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(PrintJob::class, PrintJobPolicy::class);
    }
}
