<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Shared steps of the delivery Actions. Lock order: the ORDER row first, then the delivery. */
final class DeliveryWorkflow
{
    /** @return array{0: Delivery, 1: Order} */
    public function lock(Delivery $delivery, ?int $expectedVersion = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Delivery changes must run inside a transaction.');
        }
        $order = Order::query()->lockForUpdate()->findOrFail($delivery->order_id);
        $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
        if ($expectedVersion !== null && $delivery->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The delivery was changed by someone else. Reload it and try again.');
        }

        return [$delivery, $order];
    }

    public function save(Delivery $delivery): void
    {
        $delivery->forceFill(['version' => $delivery->version + 1])->save();
    }

    /** Unpaid balance in minor units: invoice total − VERIFIED payments (never negative). */
    public static function outstanding(Order $order): int
    {
        return max(0, $order->total_minor - $order->verified_paid_minor);
    }

    public static function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
