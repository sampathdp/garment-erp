<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Support;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobQuantity;

/**
 * Everything that must hold before a cutting job can be completed (Phase 07 §7–8). Used by
 * CompleteCuttingJob under the row locks and by the job resource ("what is left").
 *
 *  - quantities: every size cut in full (no missing items);
 *  - checklist:  a cutting checklist exists, every mandatory task completed, client confirmation recorded;
 *  - quality:    quality checks exist and every MANDATORY check passed;
 *  - issues:     no unresolved BLOCKING production issue on the order (any stage).
 */
final class CuttingCompletion
{
    public function __construct(private readonly CuttingChecklists $checklists) {}

    /** @return array<string, list<string>> empty when the job can be completed */
    public function blockers(CuttingJob $job): array
    {
        $blockers = [];

        $quantities = [];
        CuttingJobQuantity::query()->where('cutting_job_id', $job->id)->orderBy('sort_order')->orderBy('id')->get()
            ->each(function (CuttingJobQuantity $q) use (&$quantities): void {
                if ($q->missing() > 0) {
                    $quantities[] = "Size {$q->size_label}: {$q->missing()} of {$q->expected_quantity} not cut yet.";
                }
            });
        if ($quantities !== []) {
            $blockers['quantities'] = $quantities;
        }

        $checklist = $this->checklists->current($job, ChecklistStage::Cutting);
        if ($checklist === null) {
            $blockers['checklist'] = ['No cutting checklist is configured for this job.'];
        } else {
            $open = array_map(fn (string $label): string => "Not completed: {$label}", $this->checklists->openMandatory($checklist));
            if (! $checklist->isClientConfirmed()) {
                $open[] = 'The client confirmation of the cutting checklist is not recorded.';
            }
            if ($open !== []) {
                $blockers['checklist'] = $open;
            }
        }

        $quality = $this->checklists->current($job, ChecklistStage::QualityControl);
        if ($quality === null) {
            $blockers['quality_checks'] = ['No quality checks are configured for this job.'];
        } elseif (($failing = $this->checklists->failingMandatoryChecks($quality)) !== []) {
            $blockers['quality_checks'] = $failing;
        }

        $issues = ProductionIssue::query()->where('order_id', $job->order_id)->blocking()->orderBy('id')->get(['id', 'type', 'description'])
            ->map(fn (ProductionIssue $i): string => "Issue #{$i->id} ({$i->type->label()}): ".mb_strimwidth((string) $i->getAttribute('description'), 0, 80, '…'))->values()->all();
        if ($issues !== []) {
            $blockers['issues'] = $issues;
        }

        return $blockers;
    }
}
