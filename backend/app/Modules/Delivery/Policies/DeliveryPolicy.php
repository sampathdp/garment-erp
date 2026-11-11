<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Policies;

use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Deliveries (Phase 08). Scope (404) → permission (403) → state (409). Delivery Staff are
 * branch-scoped (assigned branches, all pages). The Actions re-check every state under the locks.
 */
final class DeliveryPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::DELIVERY_VIEW);
    }

    public function view(User $user, Delivery $delivery): Response
    {
        return $this->decide($this->visible($user, $delivery), true);
    }

    public function dispatch(User $user, Delivery $delivery): Response
    {
        return $this->decide($this->visible($user, $delivery), $user->can(Permissions::DELIVERY_DISPATCH),
            $delivery->status === DeliveryStatus::Ready, 'Only a job that is ready for delivery can be dispatched.');
    }

    public function deliver(User $user, Delivery $delivery): Response
    {
        return $this->decide($this->visible($user, $delivery), $user->can(Permissions::DELIVERY_CONFIRM),
            $delivery->status !== DeliveryStatus::Delivered, 'This job is already delivered.');
    }

    public function uploadDocument(User $user, Delivery $delivery): Response
    {
        return $this->decide($this->visible($user, $delivery), $user->can(Permissions::DELIVERY_DISPATCH) || $user->can(Permissions::DELIVERY_CONFIRM),
            ! in_array($this->orderStatus($delivery), [OrderStatus::Completed, OrderStatus::Cancelled], true), 'Documents cannot be added to a closed job.');
    }

    /** Final completion: orders.complete, after delivery (the Action checks every other rule). */
    public function completeJob(User $user, Delivery $delivery): Response
    {
        $status = $this->orderStatus($delivery);

        return $this->decide($this->visible($user, $delivery), $user->can(Permissions::ORDERS_COMPLETE),
            $delivery->status === DeliveryStatus::Delivered && $status !== OrderStatus::Completed,
            $status === OrderStatus::Completed ? 'This job is already completed.' : 'A job can be completed only after it is delivered.');
    }

    private function orderStatus(Delivery $delivery): ?OrderStatus
    {
        $value = Order::query()->whereKey($delivery->order_id)->value('status');

        return $value instanceof OrderStatus ? $value : OrderStatus::tryFrom((string) $value);
    }

    private function visible(User $user, Delivery $delivery): bool
    {
        return $delivery->isVisibleTo($user) && $user->can(Permissions::DELIVERY_VIEW);
    }
}
