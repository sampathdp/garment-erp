<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Actions\DecidePayment;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Http\Requests\DecidePaymentRequest;
use App\Modules\Payments\Http\Requests\StorePaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentController
{
    private const SORTS = ['created_at' => 'created_at', 'amount' => 'amount_minor', 'payment_date' => 'paid_at'];

    private const COLUMNS = ['id', 'order_id', 'branch_id', 'amount_minor', 'currency', 'method', 'status', 'reference_no', 'paid_at',
        'slip_document_id', 'recorded_by', 'verified_by', 'verified_at', 'verification_notes', 'rejection_reason', 'created_at'];

    /** Verification queue and payment search (scoped to the user's branches and pages). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);
        $request->validate([
            'filter.status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'filter.order_id' => ['sometimes', 'integer'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = Payment::query()->visibleTo($user)->select(self::COLUMNS)->with(self::relations());

        $query->when($request->input('filter.status'), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->input('filter.order_id'), fn (Builder $q, $id) => $q->where('order_id', (int) $id))
            ->when(trim((string) $request->input('filter.search')), fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                ->where('reference_no', 'like', addcslashes($s, '%_\\').'%')
                ->orWhereIn('order_id', Order::query()->select('id')->where('order_no', 'like', '%'.addcslashes($s, '%_\\').'%'))));

        ListQuery::applySort($query, $request, self::SORTS, $request->input('filter.status') === PaymentStatus::PendingVerification->value ? 'created_at' : '-created_at');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), PaymentResource::class);
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($this->loadDetail($payment));
    }

    public function store(StorePaymentRequest $request, Order $order, RecordPayment $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $payment = $action->handle($actor, $order, [
            'amount_minor' => (int) $request->validated('amount_minor'),
            'method' => PaymentMethod::from((string) $request->validated('method')),
            'reference_no' => $request->validated('reference_no'),
            'payment_date' => (string) $request->validated('payment_date'),
            'notes' => $request->validated('notes'),
        ], $request->file('evidence'), $request->idempotencyKey());

        return (new PaymentResource($this->loadDetail($payment)))->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    public function verify(DecidePaymentRequest $request, Payment $payment, DecidePayment $action): PaymentResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new PaymentResource($this->loadDetail($action->handle($actor, $payment, true, $request->validated('notes'))));
    }

    public function reject(DecidePaymentRequest $request, Payment $payment, DecidePayment $action): PaymentResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new PaymentResource($this->loadDetail($action->handle($actor, $payment, false, (string) $request->validated('reason'))));
    }

    /** Payments of one order (order detail screen). */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('viewPayments', $order);
        $payments = Payment::query()->where('order_id', $order->id)->select(self::COLUMNS)->with(['recorder:id,name', 'verifier:id,name'])->orderBy('id')->get();

        return ApiResponse::data(['items' => PaymentResource::collection($payments)->resolve($request)]);
    }

    /** Evidence download: signed short-lived URL (route middleware) AND the payment policy. */
    public function evidence(Payment $payment): StreamedResponse
    {
        Gate::authorize('view', $payment);
        $document = $payment->slip()->first() ?? abort(404);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }

    /** @return array<int|string, mixed> */
    private static function relations(): array
    {
        return ['order:id,order_no,status,customer_id,page_id,total_minor,advance_required_minor,verified_paid_minor', 'order.customer:id,name', 'order.page:id,code,name', 'recorder:id,name', 'verifier:id,name'];
    }

    private function loadDetail(Payment $payment): Payment
    {
        return $payment->load([...self::relations(), 'slip:id,original_name,mime_type,size_bytes', 'verificationEvents' => fn ($q) => $q->orderBy('id'), 'verificationEvents.actor:id,name']);
    }
}
