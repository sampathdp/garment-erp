<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class CustomersServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
