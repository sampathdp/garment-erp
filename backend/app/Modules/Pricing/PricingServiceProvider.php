<?php

declare(strict_types=1);

namespace App\Modules\Pricing;

use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Policies\PriceListPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/** Product catalogue, fabric types and page-specific price lists (pricing engine: Queries\PriceQuote). */
final class PricingServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(PriceList::class, PriceListPolicy::class);
    }
}
