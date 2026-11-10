<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Resources;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobAssignment;
use App\Modules\BulkProduction\Models\BulkJobDocument;
use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkJobMeasurementChange;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\BulkReview;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Support\BulkCompletion;
use App\Modules\BulkProduction\Support\BulkMeasurementRules;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignVersionFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Bulk job for the two dashboards (list) and the work screen (detail). Bulk Designers have no
 * order access, so only the job information they need is exposed (no phone numbers, payments
 * or prices). Files are served through short-lived signed URLs that re-check the job policy.
 *
 * @mixin BulkJob
 */
final class BulkJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('bulk-jobs.index');
        $order = $this->order;
        $task = $this->designTask;
        $ttl = now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5));
        $url = fn (int $documentId): string => URL::temporarySignedRoute('bulk-jobs.documents.download', $ttl, ['job' => $this->id, 'document' => $documentId]);
        $preview = $task->approvedVersion?->files->first(fn (DesignVersionFile $f) => $f->kind === DesignFileKind::Preview);
        $list = $this->garmentListVersion;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'mode' => $this->mode?->value,
            'job_no' => $order->order_no,
            'order' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'customer' => $order->customer->name,
                'page' => $order->page->code,
                'due_date' => $order->required_delivery_date->format('Y-m-d'),
                'quantity' => $order->total_quantity,
                'is_urgent' => $order->is_urgent,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
            ],
            'branch' => $this->branch->code,
            'page' => ['code' => $order->page->code, 'name' => $order->page->name],
            'product_category' => $task->orderItem->productType->name,
            'material' => $order->material?->name,
            'design_preview_url' => $preview === null ? null : $url($preview->document_id),
            'final_design_link' => $task->finalVersion?->getAttribute('external_url'),
            'list' => $list === null ? null : ['version_no' => $list->version_no, 'total_quantity' => $list->total_quantity, 'status' => $list->status->value],
            'bulk_designer' => $this->bulkDesigner ? ['id' => $this->bulkDesigner->id, 'name' => $this->bulkDesigner->name] : null,
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),

            'assignment' => $this->when($detail, fn (): array => [
                'assigned_by' => $this->assigner?->name,
                'notes' => $this->getAttribute('assignment_notes'),
                'history' => $this->assignments->sortByDesc('id')->values()->map(fn (BulkJobAssignment $a): array => [
                    'mode' => $a->mode->value, 'bulk_designer' => $a->bulkDesigner?->name, 'supplier' => $a->supplier?->name,
                    'assigned_by' => $a->assigner->name, 'assigned_at' => $a->created_at?->toIso8601ZuluString(), 'notes' => $a->notes,
                    'reason' => $a->change_reason, 'status' => $a->ended_at === null ? 'current' : 'ended', 'ended_at' => $a->ended_at?->toIso8601ZuluString(),
                ])->all(),
            ]),
            'design' => $this->when($detail, fn (): array => [
                'approved_version_no' => $task->approvedVersion?->version_no,
                'final_link' => $task->finalVersion?->getAttribute('external_url'),
                'final_files' => $task->finalVersion?->files->map(fn (DesignVersionFile $f): array => ['name' => $f->document->original_name, 'url' => $url($f->document_id)])->all() ?? [],
                'approved_files' => $task->approvedVersion?->files->map(fn (DesignVersionFile $f): array => ['kind' => $f->kind->value, 'name' => $f->document->original_name, 'url' => $url($f->document_id)])->all() ?? [],
            ]),
            'measurements' => $this->when($detail, fn (): array => $this->measurementRows()),
            'measurement_history' => $this->when($detail, fn () => $this->measurementChanges->sortByDesc('id')->take(100)->values()->map(fn (BulkJobMeasurementChange $c): array => [
                'field' => $c->field->label, 'old_value' => $c->old_value, 'new_value' => $c->new_value, 'is_correction' => $c->is_correction,
                'reason' => $c->getAttribute('reason'), 'changed_by' => $c->changer->name, 'changed_at' => $c->created_at?->toIso8601ZuluString(),
            ])->all()),
            'checklist' => $this->when($detail, fn (): ?array => $this->checklist === null ? null : [
                'id' => $this->checklist->id,
                'stage' => $this->checklist->stage->value,
                'template' => $this->checklist->template->name.' v'.$this->checklist->template->version_no,
                'items' => $this->checklist->items->sortBy(fn (ChecklistRunItem $i) => [$i->sort_order, $i->id])->values()->map(fn (ChecklistRunItem $i): array => [
                    'id' => $i->id, 'label' => $i->label, 'is_required' => $i->is_required, 'is_completed' => $i->is_completed,
                    'completed_by' => $i->completer?->name, 'completed_at' => $i->completed_at?->toIso8601ZuluString(), 'notes' => $i->getAttribute('note'),
                ])->all(),
                'client_confirmation' => $this->checklist->client_confirmed_at === null ? null : [
                    'confirmed_at' => $this->checklist->client_confirmed_at->toIso8601ZuluString(),
                    'recorded_by' => $this->checklist->clientConfirmer?->name,
                    'channel' => $this->checklist->client_channel?->value,
                    'comment' => $this->checklist->getAttribute('client_comment'),
                    'evidence_url' => $this->checklist->getAttribute('client_evidence_document_id') === null ? null : $url((int) $this->checklist->getAttribute('client_evidence_document_id')),
                ],
            ]),
            'versions' => $this->when($detail, fn () => $this->versions->sortByDesc('version_no')->values()->map(fn (BulkVersion $v): array => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'is_final' => $v->is_final,
                'is_current' => $v->id === $this->getAttribute('current_version_id'),
                'is_approved' => $v->id === $this->getAttribute('approved_version_id'),
                'list_version_no' => $v->garmentListVersion->version_no,
                'notes' => $v->notes,
                'external_url' => $v->getAttribute('external_url'),
                'transfer_url' => $v->getAttribute('transfer_url'),
                'submitted_by' => $v->submitter->name,
                'submitted_at' => $v->submitted_at?->toIso8601ZuluString(),
                'files' => $v->files->map(fn (BulkVersionFile $f): array => [
                    'id' => $f->id, 'kind' => $f->kind->value, 'name' => $f->document->original_name, 'mime_type' => $f->document->mime_type,
                    'size_bytes' => $f->document->size_bytes, 'url' => $url($f->document_id),
                ])->all(),
                'reviews' => $v->reviews->sortBy('id')->values()->map(fn (BulkReview $r): array => [
                    'decision' => $r->decision->value, 'reason' => $r->getAttribute('reason'), 'round_no' => $r->round_no, 'is_system' => $r->is_system,
                    'reviewed_by' => $r->reviewer->name, 'reviewed_at' => $r->reviewed_at?->toIso8601ZuluString(),
                ])->all(),
            ])->all()),
            'review' => $this->when($detail, fn (): array => [
                'correction_rounds' => $this->correction_rounds,
                'approved_by' => $this->approver?->name,
                'approved_at' => $this->approved_at?->toIso8601ZuluString(),
                'approved_list_version_no' => $this->approvedListVersion?->version_no,
            ]),
            'evidence' => $this->when($detail, fn () => $this->documents->sortByDesc('id')->values()->map(fn (BulkJobDocument $d): array => [
                'kind' => $d->kind->value, 'name' => $d->document->original_name, 'note' => $d->note, 'uploaded_by' => $d->uploader->name,
                'uploaded_at' => $d->created_at?->toIso8601ZuluString(), 'url' => $url($d->document_id),
            ])->all()),
            'completion' => $this->when($detail, fn (): array => [
                'completed_by' => $this->completer?->name,
                'notes' => $this->getAttribute('completion_notes'),
                'missing' => $this->status->isClosed() || $this->mode === null ? [] : app(BulkCompletion::class)->problems($this->resource, $order, false),
            ]),
            'abilities' => $this->when($detail, fn (): array => [
                'assign' => Gate::allows('assign', $this->resource),
                'enter_measurements' => Gate::allows('enterMeasurements', $this->resource),
                'update_checklist' => Gate::allows('updateChecklist', $this->resource),
                'confirm_checklist' => Gate::allows('confirmChecklist', $this->resource),
                'submit_for_review' => Gate::allows('submitForReview', $this->resource),
                'review' => Gate::allows('review', $this->resource),
                'submit_final' => Gate::allows('submitFinal', $this->resource),
                'manage_external' => Gate::allows('manageExternal', $this->resource),
                'correct_measurements' => Gate::allows('correctMeasurements', $this->resource),
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function measurementRows(): array
    {
        $values = $this->measurements->keyBy('bulk_measurement_field_id');

        return app(BulkMeasurementRules::class)->fieldsFor($this->resource)->values()->map(function (BulkMeasurementField $f) use ($values): array {
            /** @var BulkJobMeasurement|null $m */
            $m = $values->get($f->id);

            return [
                'key' => $f->key, 'label' => $f->label, 'unit' => $f->unit, 'is_required' => $f->is_required, 'decimals' => $f->decimals,
                'min_value' => BulkMeasurementRules::display($f->min_value, $f->decimals), 'max_value' => BulkMeasurementRules::display($f->max_value, $f->decimals),
                'value' => BulkMeasurementRules::display($m?->value, $f->decimals),
                'entered_by' => $m?->enterer?->name, 'entered_at' => $m?->entered_at?->toIso8601ZuluString(),
                'updated_by' => $m?->updater?->name, 'updated_at' => $m?->updated_at?->toIso8601ZuluString(),
            ];
        })->all();
    }
}
