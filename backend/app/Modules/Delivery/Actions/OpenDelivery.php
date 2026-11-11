<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Delivery\Enums\CodStatus;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Support\DeliveryNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Opens the delivery record of a produced job — called by RunSewing::complete() in the same
 * transaction, once the order is ready_for_delivery. Idempotent (UNIQUE order_id).
 */
final class OpenDelivery
{
    public function __construct(private readonly DeliveryNotifications $notify, private readonly AuditLogger $audit) {}

    public function handle(Order $order, User $actor): Delivery
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A delivery is opened inside the sewing completion transaction.');
        }
        $existing = Delivery::query()->where('order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($order->status !== OrderStatus::ReadyForDelivery) {
            throw ApiException::conflict('not_ready_for_delivery', 'A delivery is available only after production is completed.');
        }

        $delivery = new Delivery(['order_id' => $order->id]);
        $delivery->forceFill(['branch_id' => $order->branch_id, 'status' => DeliveryStatus::Ready, 'cod_status' => CodStatus::NotApplicable, 'cod_amount_minor' => 0, 'created_by' => $actor->id, 'version' => 1])->save();
        $this->audit->record('delivery.opened', $delivery, ['after' => ['order_id' => $order->id, 'status' => DeliveryStatus::Ready->value]], actor: $actor, branchId: $order->branch_id);
        $this->notify->ready($delivery, $order, $actor);

        return $delivery;
    }
}
