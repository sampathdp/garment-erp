<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Invoices\Models\Invoice;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

final class InvoicePolicy extends ScopedPolicy
{
    public function view(User $user, Invoice $invoice): Response
    {
        return $this->allowScoped($user, $invoice, Permissions::INVOICES_VIEW);
    }
}
