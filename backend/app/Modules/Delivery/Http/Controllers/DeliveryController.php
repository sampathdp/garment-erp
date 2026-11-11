<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Delivery\Actions\CompleteJob;
use App\Modules\Delivery\Actions\ManageDelivery;
use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Delivery\Enums\DeliveryMethod;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Http\Resources\DeliveryResource;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivery dashboard and workflow (Phase 08). A delivery exists only once production is
 * completed (sewing completion opens it). Delivery Staff see their branches, all pages.
 */
final class DeliveryController
{
    private const SORTS = ['due_date', '-due_date', 'created_at', '-created_at', 'delivered_at', '-delivered_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Delivery::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:100'],
            'filter.open' => ['sometimes', 'boolean'],
            'filter.missing_documents' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        $query = Delivery::query()->visibleTo($user)
            ->join('orders', 'orders.id', '=', 'deliveries.order_id')
            ->select('deliveries.*')
            ->with([...self::relations(), 'documents:id,delivery_id,type']);

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => DeliveryStatus::tryFrom($s) !== null));
            $query->whereIn('deliveries.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->boolean('filter.open')) {
            $query->where('orders.status', '!=', OrderStatus::Completed->value);
        }
        if ($request->boolean('filter.missing_documents')) {
            $query->missingDocuments();
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)
                ->orWhereIn('orders.customer_id', Customer::query()->select('id')->where('name', 'like', $like)));
        }

        $sort = (string) $request->input('sort', 'due_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        match (ltrim($sort, '-')) {
            'due_date' => $query->orderBy('orders.required_delivery_date', $direction)->orderByDesc('orders.is_urgent')->orderBy('deliveries.id'),
            'delivered_at' => $query->orderBy('deliveries.delivered_at', $direction)->orderBy('deliveries.id', $direction),
            default => $query->orderBy('deliveries.created_at', $direction)->orderBy('deliveries.id', $direction),
        };

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), DeliveryResource::class);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        Gate::authorize('view', $delivery);

        return $this->fresh($delivery);
    }

    public function dispatch(Request $request, Delivery $delivery, ManageDelivery $action): DeliveryResource
    {
        Gate::authorize('dispatch', $delivery);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'method' => ['required', Rule::enum(DeliveryMethod::class)],
            'courier_name' => ['sometimes', 'nullable', 'string', 'max:80', 'required_if:method,courier'],
            'tracking_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'scheduled_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->dispatch($this->actor($request), $delivery, (int) $data['version'], [
            'method' => DeliveryMethod::from($data['method']), 'courier_name' => self::text($data['courier_name'] ?? null), 'tracking_no' => self::text($data['tracking_no'] ?? null),
            'scheduled_date' => $data['scheduled_date'] ?? null, 'notes' => self::text($data['notes'] ?? null),
        ]);

        return $this->fresh($delivery);
    }

    /** Delivered (or collected by the customer: method = pickup). Cash collected becomes a COD payment pending verification. */
    public function deliver(Request $request, Delivery $delivery, ManageDelivery $action): DeliveryResource
    {
        Gate::authorize('deliver', $delivery);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'method' => ['sometimes', 'nullable', Rule::enum(DeliveryMethod::class)],
            'received_by' => ['required', 'string', 'min:2', 'max:120'],
            'cod_collected_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->deliver($this->actor($request), $delivery, (int) $data['version'], isset($data['method']) ? DeliveryMethod::from($data['method']) : null,
            trim((string) $data['received_by']), (int) ($data['cod_collected_minor'] ?? 0), self::text($data['notes'] ?? null));

        return $this->fresh($delivery);
    }

    public function storeDocument(Request $request, Delivery $delivery, ManageDelivery $action): DeliveryResource
    {
        Gate::authorize('uploadDocument', $delivery);
        $data = $request->validate([
            'type' => ['required', Rule::enum(DeliveryDocumentType::class)],
            'file' => ['required', 'file', new AllowedDocument(DocumentCategory::ProofOfDelivery)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $action->addDocument($this->actor($request), $delivery, DeliveryDocumentType::from($data['type']), $file, self::text($data['notes'] ?? null));

        return $this->fresh($delivery);
    }

    /** Signed short-lived URL AND the delivery policy; only documents of this delivery. */
    public function download(Delivery $delivery, int $document): StreamedResponse
    {
        Gate::authorize('view', $delivery);
        $row = DeliveryDocument::query()->where('delivery_id', $delivery->id)->with('document')->find($document);
        abort_if($row === null, 404);
        $file = $row->document;
        $inline = in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function complete(Request $request, Delivery $delivery, CompleteJob $action): DeliveryResource
    {
        Gate::authorize('completeJob', $delivery);
        $data = $request->validate(['version' => ['required', 'integer'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $action->handle($this->actor($request), $delivery, (int) $data['version'], self::text($data['notes'] ?? null));

        return $this->fresh($delivery);
    }

    /** @return array<int|string, mixed> */
    private static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'status', 'required_delivery_date', 'is_urgent', 'total_quantity',
                'total_minor', 'verified_paid_minor', 'currency', 'completed_at', 'completed_by', 'completion_notes']),
            'order.customer:id,name,phone_e164,address_line1', 'order.page:id,code,name', 'order.material:id,name', 'branch:id,code',
            'order.items' => fn ($q) => $q->select(['id', 'order_id', 'product_type_id', 'sort_order'])->orderBy('sort_order'), 'order.items.productType:id,name',
        ];
    }

    private function fresh(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($delivery->refresh()->load([...self::relations(), 'order.completer:id,name', 'dispatcher:id,name', 'deliverer:id,name',
            'documents' => fn ($q) => $q->with(['document:id,original_name,mime_type', 'uploader:id,name'])]));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
