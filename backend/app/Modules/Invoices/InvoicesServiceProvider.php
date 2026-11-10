<?php

declare(strict_types=1);

namespace App\Modules\Invoices;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Policies\InvoicePolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class InvoicesServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
    }
}
