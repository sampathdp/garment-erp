<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Policies\PaymentPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
