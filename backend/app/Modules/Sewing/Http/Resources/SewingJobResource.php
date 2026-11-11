<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Http\Resources;

use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin SewingJob
 */
final class SewingJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'job_no' => $order->order_no,
            'order' => ['id' => $order->id, 'customer' => $order->customer->name, 'due_date' => $order->required_delivery_date->format('Y-m-d'), 'is_urgent' => $order->is_urgent,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()]],
            'branch' => $this->branch->code,
            'page' => $order->page->code,
            'product_category' => $this->orderItem->productType->name,
            'cutting_job_id' => $this->getAttribute('cutting_job_id'),
            'received_quantity' => $this->received_quantity,
            'sewn_quantity' => $this->sewn_quantity,
            'started_by' => $this->starter?->name,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_by' => $this->completer?->name,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'notes' => $this->getAttribute('notes'),
            'abilities' => ['start' => Gate::allows('start', $this->resource), 'complete' => Gate::allows('complete', $this->resource)],
        ];
    }
}
