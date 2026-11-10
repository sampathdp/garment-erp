<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderType;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Policies\OrderTypePolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderType::class, OrderTypePolicy::class);
    }
}
