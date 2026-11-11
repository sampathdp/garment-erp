<?php

declare(strict_types=1);

namespace App\Modules\Delivery;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Policies\DeliveryPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class DeliveryServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Delivery::class, DeliveryPolicy::class);
    }
}
