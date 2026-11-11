<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Resources;

use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\BulkProduction\Models\ProductionIssueEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Production issue (list and detail). The image is served through a short-lived signed URL that
 * re-checks the issue policy.
 *
 * @mixin ProductionIssue
 */
final class ProductionIssueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('production-issues.index');
        $order = $this->order;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'job_no' => $order->order_no,
            'order' => ['id' => $order->id, 'customer' => $order->customer->name, 'status' => ['value' => $order->status->value, 'label' => $order->status->label()]],
            'branch' => $this->branch->code,
            'page' => ['code' => $order->page->code, 'name' => $order->page->name],
            'cutting_job_id' => $this->cutting_job_id,
            'stage' => $this->stage->value,
            'category' => ['value' => $this->type->value, 'label' => $this->type->label(), 'bulk_related' => $this->type->isBulkRelated()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'unresolved' => $this->status->isUnresolved()],
            'description' => $this->getAttribute('description'),
            'quantity_affected' => $this->quantity_affected,
            'part' => $this->part === null ? null : ['value' => $this->part->value, 'label' => $this->part->label()],
            'size' => $this->getAttribute('size_label'),
            'is_blocking' => $this->is_blocking,
            'has_image' => $this->getAttribute('image_document_id') !== null,
            'reported_by' => $this->raiser->name,
            'reported_at' => $this->created_at?->toIso8601ZuluString(),
            'assigned_to' => $this->assignee === null ? null : ['id' => $this->assignee->id, 'name' => $this->assignee->name],
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'resolution' => $this->getAttribute('resolution'),
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),

            'image_url' => $this->when($detail && $this->getAttribute('image_document_id') !== null, fn (): string => URL::temporarySignedRoute('production-issues.image',
                now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5)), ['issue' => $this->id])),
            'resolved_by' => $this->when($detail, fn (): ?string => $this->resolver?->name),
            'closed_by' => $this->when($detail, fn (): ?string => $this->closer?->name),
            'closed_at' => $this->when($detail, fn (): ?string => $this->closed_at?->toIso8601ZuluString()),
            'next_statuses' => $this->when($detail, fn (): array => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $this->status->nextStates())),
            'history' => $this->when($detail, fn (): array => $this->events->map(fn (ProductionIssueEvent $e): array => [
                'from' => $e->from_status?->label(), 'to' => $e->to_status->label(), 'note' => $e->getAttribute('note'),
                'by' => $e->actor->name, 'at' => $e->created_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'abilities' => $this->when($detail, fn (): array => [
                'manage' => Gate::allows('manage', $this->resource),
                'transition' => Gate::allows('transition', $this->resource),
            ]),
        ];
    }
}
