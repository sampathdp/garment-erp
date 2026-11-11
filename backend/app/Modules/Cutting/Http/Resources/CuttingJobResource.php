<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Http\Resources;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Http\Controllers\CuttingJobController;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobAssignment;
use App\Modules\Cutting\Models\CuttingJobEvent;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Cutting\Support\CuttingCompletion;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Printing\Models\PrintJobGatePass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Cutting job for the cutting dashboard (list) and the cutting screen (detail).
 *
 * Quantities: expected (locked list) and cut per size; missing = expected − cut; defective =
 * unresolved damaged / incorrect-cut / missing-part pieces reported as issues; good = cut − defective.
 * `completion.blockers` lists what still prevents completion (the Action enforces the same rules).
 *
 * @mixin CuttingJob
 */
final class CuttingJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('cutting-jobs.index');
        $order = $this->order;
        $print = $this->printJob;
        $preview = $print->designVersion->files->first(fn (DesignVersionFile $f) => $f->kind === DesignFileKind::Preview);
        $defective = (int) ($this->getAttribute('defective_quantity') ?? 0);
        $list = $print->garmentListVersion;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'job_no' => $order->order_no,
            'order' => [
                'id' => $order->id,
                'customer' => $order->customer->name,
                'due_date' => $order->required_delivery_date->format('Y-m-d'),
                'is_urgent' => $order->is_urgent,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
            ],
            'branch' => $this->branch->code,
            'page' => ['code' => $order->page->code, 'name' => $order->page->name],
            'product_category' => $this->orderItem->productType->name,
            'material' => $order->material?->name,
            'quantity' => $this->planned_quantity,
            'design_preview_url' => $preview === null ? null : CuttingJobController::fileUrl($this->resource, $preview->document_id),
            'design_version_no' => $print->designVersion->version_no,
            'list' => ['version_no' => $list->version_no, 'total_quantity' => $list->total_quantity, 'status' => $list->status->value],
            'printing' => [
                'id' => $print->id,
                'printed_quantity' => $print->printing_completed_at === null ? null : $print->printed_quantity,
                'completed_at' => $print->printing_completed_at?->toIso8601ZuluString(),
                'printing_company' => $print->supplier->name ?? $print->getAttribute('recipient_name'),
                'mode' => $print->mode->value,
                'gate_passes' => $print->gatePasses->map(fn (PrintJobGatePass $g) => ['gate_pass_no' => $g->gate_pass_no, 'gate_pass_date' => $g->gate_pass_date->format('Y-m-d')])->values()->all(),
            ],
            'cutter' => $this->supervisor ? ['id' => $this->supervisor->id, 'name' => $this->supervisor->name] : null,
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'expected_completion_date' => $this->expected_completion_date?->format('Y-m-d'),
            'totals' => [
                'expected' => $this->planned_quantity,
                'cut' => $this->cut_quantity,
                'missing' => max(0, $this->planned_quantity - $this->cut_quantity),
                'defective' => $defective,
                'good' => max(0, $this->cut_quantity - $defective),
            ],
            'open_issue_count' => (int) ($this->getAttribute('open_issue_count') ?? 0),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),

            'quantities' => $this->when($detail, fn (): array => $this->quantities->map(fn (CuttingJobQuantity $q): array => [
                'id' => $q->id, 'size' => $q->size_label, 'expected' => $q->expected_quantity, 'cut' => $q->cut_quantity, 'missing' => $q->missing(),
                'updated_by' => $q->updater?->name, 'updated_at' => $q->updated_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'assignment' => $this->when($detail, fn (): array => [
                'assigned_by' => $this->assigner?->name,
                'notes' => $this->getAttribute('assignment_notes'),
                'history' => $this->assignments->map(fn (CuttingJobAssignment $a): array => [
                    'cutter' => $a->cutter->name, 'expected_completion_date' => $a->expected_completion_date->format('Y-m-d'),
                    'notes' => $a->getAttribute('notes'), 'reason' => $a->getAttribute('reason'),
                    'by' => $a->assigner->name, 'at' => $a->created_at?->toIso8601ZuluString(),
                ])->values()->all(),
            ]),
            'checklist' => $this->when($detail, fn (): ?array => $this->checklist(ChecklistStage::Cutting)),
            'quality_checks' => $this->when($detail, fn (): ?array => $this->checklist(ChecklistStage::QualityControl)),
            'issues' => $this->when($detail, fn (): array => $this->issues->map(fn (ProductionIssue $i): array => [
                'id' => $i->id, 'category' => ['value' => $i->type->value, 'label' => $i->type->label()],
                'status' => ['value' => $i->status->value, 'label' => $i->status->label()],
                'description' => $i->getAttribute('description'), 'quantity_affected' => $i->quantity_affected,
                'part' => $i->part?->value, 'size' => $i->getAttribute('size_label'), 'is_blocking' => $i->is_blocking,
                'reported_by' => $i->raiser->name, 'reported_at' => $i->created_at?->toIso8601ZuluString(), 'assigned_to' => $i->assignee?->name,
            ])->values()->all()),
            'completion' => $this->when($detail, fn (): array => [
                'completed_by' => $this->completer?->name,
                'completed_at' => $this->completed_at?->toIso8601ZuluString(),
                'notes' => $this->getAttribute('completion_notes'),
                'blockers' => $this->status->isOpen() ? app(CuttingCompletion::class)->blockers($this->resource) : [],
            ]),
            'history' => $this->when($detail, fn (): array => $this->events->map(fn (CuttingJobEvent $e): array => [
                'from' => $e->from_status?->label(), 'to' => $e->to_status->label(), 'note' => $e->getAttribute('note'),
                'by' => $e->actor->name, 'at' => $e->created_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'abilities' => $this->when($detail, fn (): array => [
                'assign' => Gate::allows('assign', $this->resource),
                'record_work' => Gate::allows('recordWork', $this->resource),
                'record_quality_check' => Gate::allows('recordQualityCheck', $this->resource),
                'confirm_checklist' => Gate::allows('confirmChecklist', $this->resource),
                'complete' => Gate::allows('complete', $this->resource),
                'report_issue' => Gate::allows('reportIssue', $this->resource),
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    private function checklist(ChecklistStage $stage): ?array
    {
        /** @var ChecklistRun|null $run */
        $run = $this->checklistRuns->first(fn (ChecklistRun $r) => $r->stage === $stage);
        if ($run === null) {
            return null;
        }
        $cutter = $this->supervisor?->name;

        return [
            'id' => $run->id,
            'items' => $run->items->map(fn (ChecklistRunItem $i): array => [
                'id' => $i->id, 'label' => $i->label, 'is_required' => $i->is_required,
                'is_completed' => $i->is_completed, 'result' => $i->result?->value, 'notes' => $i->getAttribute('note'),
                'responsible' => $i->responsible->name ?? $cutter, 'responsible_user_id' => $i->getAttribute('responsible_user_id'),
                'completed_by' => $i->completer?->name, 'completed_at' => $i->completed_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'client_confirmation' => $stage !== ChecklistStage::Cutting || $run->client_confirmed_at === null ? null : [
                'recorded_by' => $run->clientConfirmer?->name, 'recorded_at' => $run->client_confirmed_at->toIso8601ZuluString(),
                'channel' => $run->client_channel?->value, 'comment' => $run->getAttribute('client_comment'),
                'evidence_url' => $run->getAttribute('client_evidence_document_id') === null ? null : CuttingJobController::fileUrl($this->resource, (int) $run->getAttribute('client_evidence_document_id')),
            ],
        ];
    }
}
