<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\DecideUrgentApproval;
use App\Modules\Orders\Actions\SubmitOrder;
use App\Modules\Orders\Actions\UpdateOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderRequest;
use App\Modules\Orders\Http\Requests\UrgentDecisionRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use App\Support\Validation\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OrderController
{
    private const SORTS = ['created_at' => 'created_at', 'due_date' => 'required_delivery_date', 'order_no' => 'order_no'];

    private const LIST_COLUMNS = [
        'id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'order_type_id', 'status', 'payment_status', 'required_delivery_date',
        'submitted_at', 'is_urgent', 'urgent_status', 'currency', 'total_quantity', 'subtotal_minor', 'discount_minor', 'tax_minor',
        'delivery_fee_minor', 'total_minor', 'advance_percent', 'advance_required_minor', 'verified_paid_minor', 'version', 'created_at',
    ];

    /** Screen presets: drafts & pending payment, urgent approvals, Approved Orders. */
    private const GROUPS = ['intake', 'urgent', 'approved'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:300'],
            'filter.group' => ['sometimes', Rule::in(self::GROUPS)],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.customer_id' => ['sometimes', 'integer'],
            'filter.page_id' => ['sometimes', 'integer'],
            'filter.branch_id' => ['sometimes', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = Order::query()->visibleTo($user)->select(self::LIST_COLUMNS)->with(self::listRelations());

        if ($request->filled('filter.status')) {
            $statuses = array_map('trim', explode(',', (string) $request->input('filter.status')));
            foreach ($statuses as $status) {
                if (OrderStatus::tryFrom($status) === null) {
                    throw ValidationException::withMessages(['filter.status' => ["Unknown status [{$status}]."]]);
                }
            }
            $query->whereIn('status', $statuses);
        }

        $query->when($request->input('filter.group'), fn (Builder $q, string $group) => $q->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, match ($group) {
            'intake' => [OrderStatus::Draft, OrderStatus::PendingPaymentVerification, OrderStatus::PaymentRejected],
            'urgent' => [OrderStatus::UrgentApprovalPending, OrderStatus::UrgentApprovalRejected],
            default => OrderStatus::released(),
        })));

        foreach (['customer_id', 'page_id', 'branch_id'] as $column) {
            if ($request->filled("filter.{$column}")) {
                $query->where($column, (int) $request->input("filter.{$column}"));
            }
        }

        if ($search = trim((string) $request->input('filter.search'))) {
            $like = addcslashes($search, '%_\\');
            $phone = PhoneNumber::normalize($search);
            $query->where(fn (Builder $q) => $q->where('order_no', 'like', "%{$like}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$like}%")
                    ->when($phone !== null, fn (Builder $p) => $p->orWhere('phone_e164', $phone)->orWhere('whatsapp_e164', $phone))));
        }

        ListQuery::applySort($query, $request, self::SORTS, '-created_at');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), OrderResource::class);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource(self::loadDetail($order));
    }

    public function store(StoreOrderRequest $request, CreateOrder $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $order = $action->handle($actor, $request->toInput());

        return (new OrderResource(self::loadDetail($order)))->response()->setStatusCode(201);
    }

    public function update(UpdateOrderRequest $request, Order $order, UpdateOrder $action): OrderResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new OrderResource(self::loadDetail($action->handle($actor, $order, $request->toInput())));
    }

    public function submit(Request $request, Order $order, SubmitOrder $action): OrderResource
    {
        Gate::authorize('submit', $order);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        /** @var User $actor */
        $actor = $request->user();

        return new OrderResource(self::loadDetail($action->handle($actor, $order, (int) $data['version'])));
    }

    public function decideUrgent(UrgentDecisionRequest $request, Order $order, DecideUrgentApproval $action): OrderResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $order = $action->handle($actor, $order, UrgentApprovalSlot::from((string) $request->validated('slot')),
            UrgentDecision::from((string) $request->validated('decision')), $request->validated('reason'));

        return new OrderResource(self::loadDetail($order));
    }

    /** @return array<int|string, mixed> */
    private static function listRelations(): array
    {
        return [
            'branch:id,code,name', 'page:id,code,name', 'orderType:id,name',
            'customer:id,customer_code,name,phone_e164,whatsapp_e164,alt_phone_e164,address_line1',
            'items' => fn ($q) => $q->select(['id', 'order_id', 'product_type_id', 'quantity', 'unit_price_minor', 'sort_order'])->orderBy('sort_order'),
            'items.productType:id,name',
            'currentUrgentApproval.decisions',
            'designTask:id,order_id,status,designer_id,correction_rounds',
        ];
    }

    public static function loadDetail(Order $order): Order
    {
        return $order->load([
            ...self::listRelations(),
            'currentUrgentApproval.decisions.decider:id,name',
            'statusHistories' => fn ($q) => $q->orderBy('id'),
            'statusHistories.actor:id,name',
            'material:id,code,name',
            'designAttachment:id,original_name,mime_type,size_bytes',
            'designTask:id,order_id,status,designer_id,correction_rounds',
            'designTask.designer:id,name',
            'bulkJob:id,order_id,status,bulk_designer_id',
        ]);
    }
}
