<?php

declare(strict_types=1);

namespace App\Modules\Customers\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Customers are page-scoped: another page's customer is 404 even when its ID is known. */
final class CustomerPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::CUSTOMERS_VIEW);
    }

    public function view(User $user, Customer $customer): Response
    {
        return $this->allowScoped($user, $customer, Permissions::CUSTOMERS_VIEW);
    }

    /** The target page is checked by the request (must be one of the user's pages). */
    public function create(User $user): bool
    {
        return $user->can(Permissions::CUSTOMERS_MANAGE);
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->decide($customer->isVisibleTo($user) && $user->can(Permissions::CUSTOMERS_VIEW), $user->can(Permissions::CUSTOMERS_MANAGE));
    }

    public function delete(User $user, Customer $customer): Response
    {
        return $this->update($user, $customer);
    }
}
