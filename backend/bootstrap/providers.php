<?php

declare(strict_types=1);
use App\Modules\BulkProduction\BulkProductionServiceProvider;
use App\Modules\Customers\CustomersServiceProvider;
use App\Modules\Cutting\CuttingServiceProvider;
use App\Modules\Design\DesignServiceProvider;
use App\Modules\Garments\GarmentsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Invoices\InvoicesServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Organizations\OrganizationsServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Pricing\PricingServiceProvider;
use App\Modules\Printing\PrintingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationsServiceProvider::class,
    CustomersServiceProvider::class,
    PricingServiceProvider::class,
    OrdersServiceProvider::class,
    PaymentsServiceProvider::class,
    DesignServiceProvider::class,
    GarmentsServiceProvider::class,
    InvoicesServiceProvider::class,
    BulkProductionServiceProvider::class,
    PrintingServiceProvider::class,
    CuttingServiceProvider::class,
    NotificationsServiceProvider::class,
];
