<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Modules\Identity\Support\Permissions;
use App\Support\Enums\HasValues;

/**
 * An urgent order (fewer than 7 days to the due date) needs one approval in EACH slot,
 * given by two different people. The slot is granted by a permission, never by a role name:
 * the Second Admin role holds the first, the Operation Admin role the second.
 */
enum UrgentApprovalSlot: string
{
    use HasValues;

    case SecondAdmin = 'second_admin';
    case OperationAdmin = 'operation_admin';

    public function permission(): string
    {
        return match ($this) {
            self::SecondAdmin => Permissions::ORDERS_URGENT_APPROVE_ADMIN,
            self::OperationAdmin => Permissions::ORDERS_URGENT_APPROVE_OPERATIONS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SecondAdmin => 'Second Admin',
            self::OperationAdmin => 'Operation Admin',
        };
    }
}
