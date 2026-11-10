<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;

/**
 * The mandatory production data a bulk job needs before it can be completed — the same for
 * internal and external jobs (WORKFLOW_REQUIREMENTS.md §3.7b):
 *
 *   - the verified advance still covers the required percentage of the invoice;
 *   - the job refers to the order's current, confirmed (locked) name/number list; for internal
 *     jobs that is also the list version the approved bulk was prepared from;
 *   - every required measurement (bulk meter, patch meter, ...) is entered;
 *   - the checklist exists, every mandatory item is completed, and the client confirmed it.
 *
 * Internal-only conditions (approved bulk, final files, WeTransfer link) are checked by the
 * internal final submission itself.
 */
final class BulkCompletion
{
    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkMeasurementRules $measurements,
        private readonly BulkChecklists $checklists,
    ) {}

    /** @return array<string, list<string>> missing items per area (empty = ready) */
    public function problems(BulkJob $job, Order $order, bool $createChecklist = true): array
    {
        $problems = [];
        if (! $order->meetsAdvanceRequirement()) {
            $problems['advance'] = ['The verified advance no longer covers the required percentage of the invoice.'];
        }
        if (! $this->workflow->listIsCurrentAndLocked($job)) {
            $problems['list'] = ['The name/number list is being corrected; confirm it again first.'];
        } elseif ($job->isInternal() && $job->getAttribute('approved_list_version_id') !== null && $job->getAttribute('approved_list_version_id') !== $job->getAttribute('garment_list_version_id')) {
            $problems['list'] = ['The approved bulk was prepared from an older list version. Submit the bulk for review again.'];
        }
        if ($job->isInternal() && $job->getAttribute('approved_version_id') === null) {
            $problems['review'] = ['The Page Owner has not approved the bulk yet.'];
        }

        $missing = $this->measurements->missingRequired($job);
        if ($missing !== []) {
            $problems['measurements'] = array_map(fn (string $label) => "{$label} is required.", $missing);
        }

        $run = $createChecklist ? $this->checklists->ensure($job) : $this->checklists->current($job);
        if ($run === null) {
            $problems['checklist'] = ['No checklist template is configured for this job.'];
        } else {
            $open = $this->checklists->openMandatory($run);
            $checklist = array_map(fn (string $label) => "Not completed: {$label}", $open);
            if (! $run->isClientConfirmed()) {
                $checklist[] = 'The client has not confirmed the final checklist.';
            }
            if ($checklist !== []) {
                $problems['checklist'] = $checklist;
            }
        }

        return $problems;
    }

    public function assertReady(BulkJob $job, Order $order): void
    {
        $problems = $this->problems($job, $order);
        if ($problems !== []) {
            throw new ApiException(409, 'bulk_incomplete', 'The bulk job cannot be completed yet: required production data is missing.', $problems);
        }
    }
}
