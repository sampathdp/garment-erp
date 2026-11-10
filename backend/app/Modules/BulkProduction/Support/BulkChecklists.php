<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;

/**
 * Per-job checklist instances. The template is chosen by stage (internal: bulk_preparation,
 * external: bulk_external) and product category (a category-specific template wins over a
 * generic one; the newest active version wins). Its items are COPIED into the instance, so
 * later template changes never alter a job's checklist.
 */
final class BulkChecklists
{
    public static function stageFor(ProductionMode $mode): ChecklistStage
    {
        return $mode === ProductionMode::Internal ? ChecklistStage::BulkPreparation : ChecklistStage::BulkExternal;
    }

    public function current(BulkJob $job): ?ChecklistRun
    {
        return ChecklistRun::query()->where('bulk_job_id', $job->id)->whereNull('superseded_at')->latest('id')->first();
    }

    /** The job's current instance, created from the matching template when missing (null when no template exists). */
    public function ensure(BulkJob $job): ?ChecklistRun
    {
        if ($job->mode === null) {
            return null;
        }
        $current = $this->current($job);
        if ($current !== null) {
            return $current;
        }

        $stage = self::stageFor($job->mode);
        $productTypeId = BulkWorkflow::productTypeId($job);
        $template = ChecklistTemplate::query()->where('stage', $stage->value)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('product_type_id')->orWhere('product_type_id', $productTypeId))
            ->orderByRaw('CASE WHEN product_type_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('version_no')->orderByDesc('id')
            ->first();
        if ($template === null) {
            return null;
        }

        $run = ChecklistRun::query()->create(['checklist_template_id' => $template->id, 'order_id' => $job->order_id, 'bulk_job_id' => $job->id, 'stage' => $stage]);
        ChecklistTemplateItem::query()->where('checklist_template_id', $template->id)->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn (ChecklistTemplateItem $item) => ChecklistRunItem::query()->create([
                'checklist_run_id' => $run->id, 'checklist_template_item_id' => $item->id,
                'label' => $item->label, 'is_required' => $item->is_required, 'sort_order' => $item->sort_order,
            ]));

        return $run;
    }

    /** Mode changed: the old instance is kept as history and a new one is created for the new stage. */
    public function supersede(BulkJob $job): void
    {
        ChecklistRun::query()->where('bulk_job_id', $job->id)->whereNull('superseded_at')->update(['superseded_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<string> labels of mandatory items still open */
    public function openMandatory(ChecklistRun $run): array
    {
        return ChecklistRunItem::query()->where('checklist_run_id', $run->id)->where('is_required', true)->where('is_completed', false)
            ->orderBy('sort_order')->orderBy('id')->pluck('label')->all();
    }

    /** The customer confirmation is no longer valid (bulk sent back for correction, list reopened). */
    public function resetClientConfirmation(BulkJob $job): bool
    {
        return ChecklistRun::query()->where('bulk_job_id', $job->id)->whereNull('superseded_at')->whereNotNull('client_confirmed_at')
            ->update(['client_confirmed_at' => null, 'client_confirmed_by' => null, 'client_channel' => null, 'client_comment' => null,
                'client_evidence_document_id' => null, 'updated_at' => now()]) > 0;
    }
}
