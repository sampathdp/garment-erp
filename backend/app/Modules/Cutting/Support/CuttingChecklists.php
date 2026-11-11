<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Support;

use App\Modules\BulkProduction\Enums\ChecklistResult;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\Cutting\Models\CuttingJob;

/**
 * The two checklist instances of a cutting job, built from configurable templates:
 *
 *  - cutting checklist (stage `cutting`): tasks marked completed / not completed with the
 *    responsible user, completion date and notes; the client confirms the final checklist.
 *  - quality checks (stage `quality_control`): each check records pass / fail / n/a with a note.
 *    A MANDATORY check must PASS before cutting can be completed.
 *
 * The template is chosen by stage and product category (a category template wins over a generic
 * one; the newest active version wins). Items are COPIED into the instance at assignment, so
 * later template changes never alter an existing job.
 */
final class CuttingChecklists
{
    public const STAGES = [ChecklistStage::Cutting, ChecklistStage::QualityControl];

    public function current(CuttingJob $job, ChecklistStage $stage): ?ChecklistRun
    {
        return ChecklistRun::query()->where('cutting_job_id', $job->id)->where('stage', $stage->value)->whereNull('superseded_at')->latest('id')->first();
    }

    /** The job's instance for the stage, created from the matching template when missing (null when no template exists). */
    public function ensure(CuttingJob $job, ChecklistStage $stage): ?ChecklistRun
    {
        $current = $this->current($job, $stage);
        if ($current !== null) {
            return $current;
        }

        $productTypeId = CuttingWorkflow::productTypeId($job);
        $template = ChecklistTemplate::query()->where('stage', $stage->value)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('product_type_id')->orWhere('product_type_id', $productTypeId))
            ->orderByRaw('CASE WHEN product_type_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('version_no')->orderByDesc('id')
            ->first();
        if ($template === null) {
            return null;
        }

        $run = ChecklistRun::query()->create(['checklist_template_id' => $template->id, 'order_id' => $job->order_id, 'cutting_job_id' => $job->id, 'stage' => $stage]);
        ChecklistTemplateItem::query()->where('checklist_template_id', $template->id)->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn (ChecklistTemplateItem $item) => ChecklistRunItem::query()->create([
                'checklist_run_id' => $run->id, 'checklist_template_item_id' => $item->id,
                'label' => $item->label, 'is_required' => $item->is_required, 'sort_order' => $item->sort_order,
            ]));

        return $run;
    }

    public function ensureAll(CuttingJob $job): void
    {
        foreach (self::STAGES as $stage) {
            $this->ensure($job, $stage);
        }
    }

    /** @return list<string> labels of mandatory cutting tasks still open */
    public function openMandatory(ChecklistRun $run): array
    {
        return ChecklistRunItem::query()->where('checklist_run_id', $run->id)->where('is_required', true)->where('is_completed', false)
            ->orderBy('sort_order')->orderBy('id')->pluck('label')->all();
    }

    /** @return list<string> mandatory quality checks that have not passed ("Label: failed" / "Label: not checked") */
    public function failingMandatoryChecks(ChecklistRun $run): array
    {
        return ChecklistRunItem::query()->where('checklist_run_id', $run->id)->where('is_required', true)
            ->where(fn ($q) => $q->whereNull('result')->orWhere('result', '!=', ChecklistResult::Pass->value))
            ->orderBy('sort_order')->orderBy('id')->get(['label', 'result'])
            ->map(fn (ChecklistRunItem $i): string => $i->label.': '.match ($i->result) {
                ChecklistResult::Fail => 'failed',
                ChecklistResult::NotApplicable => 'marked not applicable (a mandatory check must pass)',
                default => 'not checked',
            })->values()->all();
    }
}
