<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Resources;

use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryDocument;
use App\Modules\Delivery\Support\DeliveryWorkflow;
use App\Modules\Delivery\Support\JobCompletion;
use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Delivery for the delivery dashboard (list) and screen (detail). Documents are served through
 * short-lived signed URLs that re-check the delivery policy.
 *
 * @mixin Delivery
 */
final class DeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('deliveries.index');
        $order = $this->order;
        $money = fn (int $minor): array => ['amount_minor' => $minor, 'formatted' => DeliveryWorkflow::money($minor, $order->currency)];
        $item = $order->items->first();
        $missing = JobCompletion::missingDocuments($this->resource);
        $ttl = now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5));

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'job_no' => $order->order_no,
            'order' => ['id' => $order->id, 'due_date' => $order->required_delivery_date->format('Y-m-d'), 'is_urgent' => $order->is_urgent,
                'completed_at' => $order->completed_at?->toIso8601ZuluString()],
            'customer' => ['name' => $order->customer->name, 'phone' => $order->customer->getAttribute('phone_e164'), 'address' => $order->customer->getAttribute('address_line1')],
            'branch' => $this->branch->code,
            'page' => ['code' => $order->page->code, 'name' => $order->page->name],
            'quantity' => $order->total_quantity,
            'product' => ['category' => $item?->productType?->name, 'material' => $order->material?->name],
            'production_status' => ['value' => $order->status->value, 'label' => $order->status->label(), 'is_completed' => $order->status === OrderStatus::Completed],
            'method' => $this->method === null ? null : ['value' => $this->method->value, 'label' => $this->method->label()],
            'balance' => $money(DeliveryWorkflow::outstanding($order)),
            'cod' => ['amount' => $money($this->cod_amount_minor), 'collected' => $this->cod_collected_minor === null ? null : $money($this->cod_collected_minor), 'status' => $this->cod_status->value],
            'documents_missing' => array_map(fn (array $set) => array_map(fn (DeliveryDocumentType $t) => ['value' => $t->value, 'label' => $t->label()], $set), $missing),
            'dispatched_at' => $this->dispatched_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),

            'dispatch' => $this->when($detail, fn (): array => [
                'by' => $this->dispatcher?->name, 'courier_name' => $this->getAttribute('courier_name'), 'tracking_no' => $this->getAttribute('tracking_no'),
                'scheduled_date' => $this->getAttribute('scheduled_date')?->format('Y-m-d'),
            ]),
            'delivery' => $this->when($detail, fn (): array => ['by' => $this->deliverer?->name, 'received_by' => $this->getAttribute('received_by_name'), 'notes' => $this->getAttribute('notes')]),
            'documents' => $this->when($detail, fn (): array => $this->documents->sortByDesc('id')->values()->map(fn (DeliveryDocument $d): array => [
                'id' => $d->id, 'type' => ['value' => $d->type->value, 'label' => $d->type->label()], 'name' => $d->document->original_name,
                'mime_type' => $d->document->mime_type, 'notes' => $d->getAttribute('notes'), 'uploaded_by' => $d->uploader->name, 'uploaded_at' => $d->created_at?->toIso8601ZuluString(),
                'url' => URL::temporarySignedRoute('deliveries.documents.download', $ttl, ['delivery' => $this->id, 'document' => $d->id]),
            ])->all()),
            'completion' => $this->when($detail, fn (): array => [
                'completed_by' => $order->getAttribute('completed_by') === null ? null : $order->completer?->name,
                'completed_at' => $order->completed_at?->toIso8601ZuluString(),
                'notes' => $order->getAttribute('completion_notes'),
                'blockers' => $order->status === OrderStatus::Completed ? [] : app(JobCompletion::class)->blockers($order, $this->resource),
            ]),
            'abilities' => $this->when($detail, fn (): array => [
                'dispatch' => Gate::allows('dispatch', $this->resource),
                'deliver' => Gate::allows('deliver', $this->resource),
                'upload_document' => Gate::allows('uploadDocument', $this->resource),
                'complete_job' => Gate::allows('completeJob', $this->resource),
            ]),
        ];
    }
}
