<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;

/**
 * | Event                 | Recipients                                                          |
 * |-----------------------|---------------------------------------------------------------------|
 * | delivery.ready        | Delivery staff (delivery.dispatch) in scope, Page Owners, sales owner |
 * | delivery.dispatched   | Page Owners, sales owner                                            |
 * | delivery.delivered    | Page Owners, sales owner; payment verifiers when COD was collected  |
 * | order.completed       | Page Owners, sales owner, Operation Admins (orders.view + scope)    |
 *
 * The acting user is never notified (Notify).
 */
final class DeliveryNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function ready(Delivery $delivery, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::DELIVERY_DISPATCH, $order->branch_id, $order->page_id)
            ->merge($r->pageOwners($order->page_id))->merge($this->salesOwner($r, $order)),
            $this->make('delivery.ready', 'Job ready for delivery', "Job {$order->order_no} is produced and ready for delivery.", $delivery, $order), $actor);
    }

    public function dispatched(Delivery $delivery, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id)->merge($this->salesOwner($r, $order)),
            $this->make('delivery.dispatched', 'Job dispatched', "Job {$order->order_no} is out for delivery ({$delivery->method?->label()}).", $delivery, $order), $actor);
    }

    public function delivered(Delivery $delivery, Order $order, bool $codCollected, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id)->merge($this->salesOwner($r, $order))
            ->merge($codCollected ? $r->withPermission(Permissions::PAYMENTS_VERIFY, $order->branch_id, $order->page_id) : []),
            $this->make('delivery.delivered', 'Job delivered', "Job {$order->order_no} was delivered".($codCollected ? '. A cash-on-delivery payment is waiting for verification.' : '.'), $delivery, $order), $actor);
    }

    public function completed(Delivery $delivery, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id)->merge($this->salesOwner($r, $order))
            ->merge($r->withPermission(Permissions::ORDERS_COMPLETE, $order->branch_id, $order->page_id)),
            $this->make('order.completed', 'Job completed', "Job {$order->order_no} is completed.", $delivery, $order), $actor);
    }

    /** @return iterable<int, User> */
    private function salesOwner(Recipients $r, Order $order): iterable
    {
        $id = $order->getAttribute('sales_owner_id');

        return $id === null ? [] : $r->users((int) $id);
    }

    private function make(string $event, string $title, string $message, Delivery $delivery, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/deliveries/view/?id={$delivery->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'delivery_id' => $delivery->id]);
    }
}
