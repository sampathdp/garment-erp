<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/** Soft delete, only for customers without orders (orders keep their customer forever). */
final class DeleteCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Customer $customer): void
    {
        DB::transaction(function () use ($actor, $customer): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->orders()->exists()) {
                throw ApiException::conflict('customer_has_orders', 'Customers with orders cannot be deleted.');
            }
            $customer->delete();
            $this->audit->record('customers.deleted', $customer, ['before' => ['customer_code' => $customer->customer_code]], actor: $actor);
        });
    }
}
