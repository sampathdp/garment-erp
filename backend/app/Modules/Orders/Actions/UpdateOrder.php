<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\OrderNotifier;
use App\Modules\Orders\Support\OrderPricing;
use App\Modules\Organizations\Models\Page;
use App\Modules\Pricing\Models\ProductType;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Edits the job specification and price of a draft or not-yet-approved order (the policy checks
 * the state). The status is never accepted from the client: a changed due date re-runs the
 * seven-day rule, and every change re-evaluates the release (EvaluateOrderRelease).
 */
final class UpdateOrder
{
    public function __construct(
        private readonly ApplyUrgencyRule $urgency,
        private readonly EvaluateOrderRelease $release,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $input  validated: version + any of due_date, product_type_id, product_type_other, gross_quantity, advance_amount_minor, delivery_fee_minor, discount_minor */
    public function handle(User $actor, Order $order, array $input): Order
    {
        return DB::transaction(function () use ($actor, $order, $input): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->version !== (int) $input['version']) {
                throw ApiException::conflict('version_conflict', 'This order was changed by someone else. Reload it and try again.');
            }
            /** @var OrderItem $item */
            $item = OrderItem::query()->where('order_id', $order->id)->orderBy('sort_order')->lockForUpdate()->firstOrFail();

            $before = [
                'due_date' => $order->required_delivery_date->format('Y-m-d'),
                'product_type_id' => $item->product_type_id, 'product_type_other' => $item->product_type_other,
                'gross_quantity' => $item->quantity, 'advance_amount_minor' => $order->advance_required_minor,
                'delivery_fee_minor' => $order->delivery_fee_minor, 'discount_minor' => $order->discount_minor,
            ];
            $after = array_merge($before, array_intersect_key($input, $before));
            if (array_key_exists('product_type_id', $input) || array_key_exists('product_type_other', $input)) {
                $productType = ProductType::query()->withTrashed()->findOrFail((int) $after['product_type_id']);
                if ($productType->code !== 'other') {
                    $after['product_type_other'] = null;
                } elseif (array_key_exists('product_type_other', $input)) {
                    $after['product_type_other'] = trim((string) $input['product_type_other']);
                }
            }
            $changes = array_diff_assoc($after, $before);
            if ($changes === []) {
                return $order;
            }

            if (isset($changes['due_date']) && $changes['due_date'] <= BusinessDate::today()->toDateString()) {
                throw new ApiException(422, 'validation_failed', 'The due date must be after today.', ['due_date' => ['The due date must be after today.']]);
            }

            $order->fill(['required_delivery_date' => $after['due_date']]);
            $item->product_type_id = (int) $after['product_type_id'];
            $item->product_type_other = $after['product_type_other'];
            $minimum = (int) Page::query()->whereKey($order->page_id)->value('min_advance_minor');
            OrderPricing::apply($order, $item, (int) $after['gross_quantity'], (int) $after['delivery_fee_minor'], (int) $after['discount_minor'], (int) $after['advance_amount_minor'], $minimum);
            $order->forceFill(['version' => $order->version + 1])->save();
            $item->save();

            $this->audit->record('orders.updated', $order, ['before' => array_intersect_key($before, $changes), 'after' => $changes], actor: $actor);

            $urgency = null;
            if (isset($changes['due_date']) && ! $order->status->isReleased() && $order->submitted_at !== null) {
                $urgency = $this->urgency->handle($order, $actor);
            }

            $this->release->handle($order, $actor, isset($changes['due_date']) ? 'Due date changed.' : 'Job specification changed.');

            if ($urgency !== null && $urgency['urgent']) {
                $this->notifier->urgentRequested($order, $urgency['days']);
            }

            return $order;
        });
    }
}
