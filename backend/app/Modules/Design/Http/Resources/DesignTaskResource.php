<?php

declare(strict_types=1);

namespace App\Modules\Design\Http\Resources;

use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignReview;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Organizations\Support\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Design task with the job information the designer needs. Designers have no order access, so
 * only the fields relevant to the design are exposed (no customer phone numbers or payments).
 *
 * @mixin DesignTask
 */
final class DesignTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('design-tasks.index');
        $order = $this->order;
        $ttl = now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5));

        return [
            'id' => $this->id,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'designer' => $this->designer ? ['id' => $this->designer->id, 'name' => $this->designer->name] : null,
            'correction_rounds' => $this->correction_rounds,
            'order' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'branch' => ['code' => $order->branch->code, 'name' => $order->branch->name],
                'page' => ['code' => $order->page->code, 'name' => $order->page->name],
                'customer' => $order->customer->name,
                'product_category' => $this->orderItem->productType->name,
                'quantity' => $order->total_quantity,
                'due_date' => $order->required_delivery_date->format('Y-m-d'),
                'is_urgent' => $order->is_urgent,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'job_details' => $this->when($detail, fn (): array => [
                'material' => $order->material?->name,
                'material_details' => $order->material_details,
                'design_instructions' => $order->design_instructions,
                'custom_notes' => $order->custom_notes,
                'overall_remarks' => $order->overall_remarks,
                'attachment' => $order->design_attachment_document_id === null ? null : [
                    'url' => URL::temporarySignedRoute('orders.job-details.attachment', $ttl, ['order' => $order->id]),
                ],
            ]),
            'max_correction_rounds' => $this->when($detail, fn () => app(WorkflowSettings::class)->maxCorrectionRounds()),
            'versions' => $this->when($detail, fn () => $this->versions->sortByDesc('version_no')->values()->map(fn (DesignVersion $v): array => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'previous_version_id' => $v->getAttribute('previous_version_id'),
                'is_final' => $v->getAttribute('is_final'),
                'is_approved' => $v->id === $this->getAttribute('approved_version_id'),
                'notes' => $v->notes,
                'external_url' => $v->getAttribute('external_url'),
                'uploaded_by' => $v->creator->name,
                'uploaded_at' => $v->submitted_at?->toIso8601ZuluString(),
                'files' => $v->files->map(fn (DesignVersionFile $f): array => [
                    'id' => $f->id, 'kind' => $f->kind->value, 'name' => $f->document->original_name, 'mime_type' => $f->document->mime_type,
                    'size_bytes' => $f->document->size_bytes,
                    'url' => URL::temporarySignedRoute('design-tasks.files.download', $ttl, ['task' => $this->id, 'file' => $f->id]),
                ])->all(),
                'reviews' => $v->reviews->map(fn (DesignReview $r): array => [
                    'decision' => $r->decision->value, 'channel' => $r->channel->value, 'comment' => $r->customer_comment,
                    'recorded_by' => $r->recorder->name, 'at' => $r->decided_at?->toIso8601ZuluString(),
                ])->all(),
            ])->all()),
            'corrections' => $this->when($detail, fn () => $this->corrections->sortBy('round_no')->values()->map(fn (DesignCorrectionRequest $c): array => [
                'round_no' => $c->round_no, 'reason' => $c->description, 'requested_by' => $c->requester?->name,
                'requested_at' => $c->created_at?->toIso8601ZuluString(), 'resolved_in_version_id' => $c->getAttribute('resolved_in_version_id'),
                'override_reason' => $c->override_reason,
            ])->all()),
            'abilities' => $this->when($detail, fn (): array => [
                'assign' => Gate::allows('assignDesigner', $this->resource),
                'upload_version' => Gate::allows('uploadVersion', $this->resource),
                'request_correction' => Gate::allows('requestCorrection', $this->resource),
                'approve' => Gate::allows('approve', $this->resource),
                'submit_final' => Gate::allows('submitFinal', $this->resource),
            ]),
        ];
    }
}
