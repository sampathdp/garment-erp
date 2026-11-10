<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Customers\Actions\SaveCustomer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Support\AdvanceRule;
use App\Modules\Orders\Support\OrderPricing;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an order (job) from the order form: customer information + job specification + agreed
 * price. Garment item details, design instructions, attachments and remarks are added after
 * payment verification (later phases). Optionally submits it in the same transaction.
 */
final class CreateOrder
{
    public function __construct(
        private readonly GenerateJobNumber $jobNumber,
        private readonly SaveCustomer $customers,
        private readonly SubmitOrder $submit,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{branch_id: int, page_id: int, order_type_id: int, due_date: string, product_type_id: int, gross_quantity: int,
     *               unit_price_minor: int, delivery_fee_minor: int|null, discount_minor: int, customer_id: int|null,
     *               customer: array<string, mixed>|null, confirm_distinct: bool, submit: bool}  $input
     */
    public function handle(User $actor, array $input): Order
    {
        return DB::transaction(function () use ($actor, $input): Order {
            $branch = Branch::query()->select(['id', 'code'])->findOrFail($input['branch_id']);
            $page = Page::query()->select(['id', 'code', 'currency', 'default_advance_percent', 'default_delivery_fee_minor'])->findOrFail($input['page_id']);

            $customer = $this->customer($actor, $input, $page, $branch);

            $order = new Order([
                'order_no' => $this->jobNumber->handle($branch->code, $page->code),
                'branch_id' => $branch->id,
                'page_id' => $page->id,
                'customer_id' => $customer->id,
                'order_type_id' => $input['order_type_id'],
                'sales_owner_id' => $actor->id,
                'required_delivery_date' => $input['due_date'],
                'currency' => $page->currency,
                'created_by' => $actor->id,
            ]);
            $order->forceFill([
                'status' => OrderStatus::Draft,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'urgent_status' => UrgentStatus::NotRequired,
                'is_urgent' => false,
                'verified_paid_minor' => 0,
                'version' => 1,
                // Snapshot of the page's advance percentage; never below the 30% minimum.
                'advance_percent' => max(AdvanceRule::MINIMUM_PERCENT, $page->default_advance_percent),
            ]);

            $item = new OrderItem(['product_type_id' => $input['product_type_id'], 'sort_order' => 0]);
            OrderPricing::apply($order, $item, $input['gross_quantity'], $input['unit_price_minor'],
                $input['delivery_fee_minor'] ?? $page->default_delivery_fee_minor, $input['discount_minor']);

            $order->save();
            $item->order_id = $order->id;
            $item->save();

            OrderStatusHistory::query()->create(['order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Draft, 'reason' => 'Created.', 'actor_id' => $actor->id]);
            $this->audit->record('orders.created', $order, ['after' => [
                'order_no' => $order->order_no, 'customer_id' => $customer->id, 'order_type_id' => $order->order_type_id,
                'due_date' => $input['due_date'], 'product_type_id' => $input['product_type_id'], 'gross_quantity' => $input['gross_quantity'],
                'total_minor' => $order->total_minor, 'advance_required_minor' => $order->advance_required_minor,
            ]], actor: $actor);

            if ($input['submit']) {
                $this->submit->submitLocked($actor, $order);
            }

            return $order;
        });
    }

    /** @param  array<string, mixed>  $input */
    private function customer(User $actor, array $input, Page $page, Branch $branch): Customer
    {
        if ($input['customer_id'] !== null) {
            $customer = Customer::query()->find($input['customer_id']);
            // Customers belong to a page: an order for page B cannot reuse page A's customer record.
            if ($customer === null || ! $customer->isVisibleTo($actor) || ($customer->page_id !== null && $customer->page_id !== $page->id)) {
                throw new ApiException(422, 'validation_failed', 'Select a customer of this page.', ['customer_id' => ['Select a customer of this page.']]);
            }

            return $customer;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $input['customer'];

        return $this->customers->resolve($actor, $page->id, $branch->id, $attributes, $input['confirm_distinct']);
    }
}
