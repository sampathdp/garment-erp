<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Models\Order;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Orders ("jobs"). Scope = branch + page of the order, so a user who knows another page's
 * job ID gets 404. Action rules follow ScopedPolicy: scope → permission → state.
 */
final class OrderPolicy extends ScopedPolicy
{
    /** Design instructions can change until the design is approved. */
    private const DESIGN_INSTRUCTION_STATES = [
        OrderStatus::Draft, OrderStatus::PendingPaymentVerification, OrderStatus::PaymentRejected,
        OrderStatus::UrgentApprovalPending, OrderStatus::UrgentApprovalRejected, OrderStatus::Confirmed,
        OrderStatus::DesignInProgress, OrderStatus::DesignReview,
    ];

    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ORDERS_VIEW);
    }

    public function view(User $user, Order $order): Response
    {
        return $this->allowScoped($user, $order, Permissions::ORDERS_VIEW);
    }

    /** The branch and page are checked by the request (must be assigned to the user). */
    public function create(User $user): bool
    {
        return $user->can(Permissions::ORDERS_CREATE);
    }

    /** Job specification and price can change until the order is approved. */
    public function update(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::ORDERS_UPDATE),
            $order->status === OrderStatus::Draft || $order->status->isPreApproval(),
            'Approved orders cannot be edited here.',
        );
    }

    public function submit(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::ORDERS_SUBMIT),
            $order->status === OrderStatus::Draft,
            'Only draft orders can be submitted.',
        );
    }

    /** Advance (and later balance) payments: after submission, while the order is active. */
    public function recordPayment(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::PAYMENTS_RECORD),
            $order->status !== OrderStatus::Draft && $order->status->isActive(),
            'Payments can be recorded after the order is submitted and while it is active.',
        );
    }

    public function viewPayments(User $user, Order $order): Response
    {
        return $this->decide($order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW), $user->can(Permissions::PAYMENTS_VIEW));
    }

    /** Urgent approval: one slot, granted by the slot's permission. */
    public function decideUrgent(User $user, Order $order, UrgentApprovalSlot $slot): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can($slot->permission()),
            $order->status === OrderStatus::UrgentApprovalPending,
            'This order is not waiting for an urgent approval.',
        );
    }

    /**
     * Additional order information (fabric, material, design instructions, notes, attachment,
     * remarks). Only after payment approval and until the design is approved.
     */
    public function manageJobDetails(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::DESIGNS_INSTRUCT),
            $order->acceptsJobDetails(),
            'Job details can be added after payment approval and until the design is approved.',
        );
    }

    /** Submitting the details creates the design task (once). */
    public function submitJobDetails(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::DESIGNS_INSTRUCT),
            $order->status === OrderStatus::Confirmed,
            'Job details can be submitted once, after payment approval.',
        );
    }

    public function updateCustomerInfo(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user),
            $user->can(Permissions::CUSTOMERS_MANAGE),
            ! $order->status->isTerminal(),
            'Customer information cannot change on a completed or cancelled order.',
        );
    }

    public function manageDesignInstructions(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user),
            $user->can(Permissions::DESIGNS_INSTRUCT),
            in_array($order->status, self::DESIGN_INSTRUCTION_STATES, true),
            'Design instructions cannot change after the design is approved.',
        );
    }

    public function assignBulk(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user),
            $user->can(Permissions::BULK_ASSIGN),
            in_array($order->status, [OrderStatus::DesignApproved, OrderStatus::BulkAssigned], true),
            'Bulk production can only be assigned after the design is approved.',
        );
    }

    public function approveBulk(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user),
            $user->can(Permissions::BULK_APPROVE),
            $order->status === OrderStatus::BulkPreparation,
            'Bulk can only be approved while it is in preparation.',
        );
    }

    public function reportIssue(User $user, Order $order): Response
    {
        return $this->decide(
            $order->isVisibleTo($user) && $user->can(Permissions::ORDERS_VIEW),
            $user->can(Permissions::ISSUES_REPORT),
            $order->status->isActive(),
            'Issues can only be reported on active orders.',
        );
    }
}
