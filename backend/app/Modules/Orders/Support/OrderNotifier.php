<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Order-intake notifications (WORKFLOW_REQUIREMENTS.md §3.13). Recipients are resolved AFTER
 * the transaction commits and delivered through the queue (WorkflowNotification is queued).
 *
 * | Event                         | Recipients                                                        |
 * |-------------------------------|-------------------------------------------------------------------|
 * | order.created                 | Page Owners of the page, payment verifiers in scope               |
 * | payment.pending               | Payment verifiers in scope (never the recorder)                   |
 * | payment.confirmed / rejected  | Recorder, Page Owners                                             |
 * | advance.below_minimum         | Page Owners, sales owner; the Operation Admin only when they have |
 * |                               | already approved this order as urgent (they planned for it)       |
 * | urgent.approval_requested     | Holders of each urgent approval slot in scope                     |
 * | urgent.slot_decided           | The other slot's approvers (when one slot approved)               |
 * | urgent.approved / rejected    | Order creator, sales owner, Page Owners                           |
 */
final class OrderNotifier
{
    public function __construct(private readonly Recipients $recipients) {}

    public function orderCreated(Order $order, User $actor): void
    {
        $this->later(fn () => $this->send(
            $this->recipients->pageOwners($order->page_id, [$actor->id])
                ->merge($this->recipients->withPermission(Permissions::PAYMENTS_VERIFY, $order->branch_id, $order->page_id, [$actor->id])),
            new WorkflowNotification('order.created', 'New order', "Job {$order->order_no} was created and is waiting for its advance payment.", self::orderLink($order), self::context($order)),
        ));
    }

    public function urgentRequested(Order $order, int $days): void
    {
        $this->later(function () use ($order, $days): void {
            foreach (UrgentApprovalSlot::cases() as $slot) {
                $this->send(
                    $this->recipients->withPermission($slot->permission(), $order->branch_id, $order->page_id, [$order->created_by]),
                    new WorkflowNotification('urgent.approval_requested', 'Urgent job needs approval',
                        "Job {$order->order_no} is due in {$days} day(s). {$slot->label()} approval is required.", self::orderLink($order), [...self::context($order), 'slot' => $slot->value]),
                );
            }
        });
    }

    public function urgentSlotApproved(Order $order, UrgentApprovalSlot $decided, User $actor): void
    {
        $this->later(function () use ($order, $decided, $actor): void {
            foreach (UrgentApprovalSlot::cases() as $slot) {
                if ($slot === $decided) {
                    continue;
                }
                $this->send(
                    $this->recipients->withPermission($slot->permission(), $order->branch_id, $order->page_id, [$actor->id]),
                    new WorkflowNotification('urgent.slot_decided', 'Urgent job partly approved',
                        "{$decided->label()} approved urgent job {$order->order_no}. {$slot->label()} approval is still required.", self::orderLink($order), self::context($order)),
                );
            }
        });
    }

    public function urgentDecided(Order $order, bool $approved, ?string $reason): void
    {
        $this->later(fn () => $this->send(
            $this->recipients->users($order->created_by, $order->sales_owner_id)->merge($this->recipients->pageOwners($order->page_id)),
            $approved
                ? new WorkflowNotification('urgent.approved', 'Urgent job approved', "Urgent job {$order->order_no} was approved by the Second Admin and the Operation Admin.", self::orderLink($order), self::context($order))
                : new WorkflowNotification('urgent.rejected', 'Urgent job rejected', "Urgent job {$order->order_no} was rejected: {$reason}", self::orderLink($order), self::context($order)),
        ));
    }

    public function paymentPending(Order $order, int $paymentId, int $amountMinor, User $recorder): void
    {
        $this->later(fn () => $this->send(
            $this->recipients->withPermission(Permissions::PAYMENTS_VERIFY, $order->branch_id, $order->page_id, [$recorder->id]),
            new WorkflowNotification('payment.pending', 'Payment waiting for verification',
                'A payment of '.self::money($amountMinor, $order->currency)." for job {$order->order_no} needs verification.", self::paymentLink($paymentId), [...self::context($order), 'payment_id' => $paymentId]),
        ));
    }

    public function paymentDecided(Order $order, int $paymentId, int $recorderId, bool $verified, ?string $reason, User $actor): void
    {
        $this->later(fn () => $this->send(
            $this->recipients->users($recorderId)->merge($this->recipients->pageOwners($order->page_id))->reject(fn (User $u) => $u->id === $actor->id),
            $verified
                ? new WorkflowNotification('payment.confirmed', 'Payment confirmed', "A payment for job {$order->order_no} was confirmed.", self::paymentLink($paymentId), [...self::context($order), 'payment_id' => $paymentId])
                : new WorkflowNotification('payment.rejected', 'Payment rejected', "A payment for job {$order->order_no} was rejected: {$reason}", self::paymentLink($paymentId), [...self::context($order), 'payment_id' => $paymentId]),
        ));
    }

    /** @param  int|null  $operationsApproverId  the Operation Admin who already approved this urgent order */
    public function advanceBelowMinimum(Order $order, ?int $operationsApproverId, bool $notifyOperations = false): void
    {
        $shortfall = $order->advanceShortfallMinor();
        $this->later(function () use ($order, $operationsApproverId, $shortfall, $notifyOperations): void {
            $users = $this->recipients->pageOwners($order->page_id)->merge($this->recipients->users($order->sales_owner_id));
            if ($operationsApproverId !== null) {
                $users = $users->merge($this->recipients->users($operationsApproverId));
            }
            // A released job whose advance falls short (e.g. the invoice is higher than the estimate):
            // operations must hold it.
            if ($notifyOperations) {
                $users = $users->merge($this->recipients->withPermission(Permissions::BULK_ASSIGN, $order->branch_id, $order->page_id));
            }
            $this->send($users, new WorkflowNotification('advance.below_minimum', 'Advance below 30%',
                "Verified payments for job {$order->order_no} are below the required advance. Missing: ".self::money($shortfall, $order->currency).'. The job cannot enter production.',
                self::orderLink($order), [...self::context($order), 'shortfall_minor' => $shortfall]));
        });
    }

    private function later(callable $callback): void
    {
        DB::afterCommit($callback);
    }

    /** @param  Collection<int, User>  $users */
    private function send(Collection $users, WorkflowNotification $notification): void
    {
        $unique = $users->unique('id')->values();
        if ($unique->isNotEmpty()) {
            Notification::send($unique, $notification);
        }
    }

    /** @return array<string, scalar|null> */
    private static function context(Order $order): array
    {
        return ['order_id' => $order->id, 'order_no' => $order->order_no];
    }

    private static function orderLink(Order $order): string
    {
        return "/orders/view/?id={$order->id}";
    }

    private static function paymentLink(int $paymentId): string
    {
        return "/payments/view/?id={$paymentId}";
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
