<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\SampleDecision;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Support\PrintingWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Physical printing, run by the Operation Admin:
 *  - start():    only from an APPROVED sample (re-checked under the lock, including the approved
 *                sample row itself) → physical_printing.
 *  - complete(): records the printed quantity and notes → printing_completed. The order stays
 *                in `printing`; moving on to cutting is a later phase.
 */
final class RunPhysicalPrinting
{
    public function __construct(private readonly PrintingWorkflow $workflow) {}

    public function start(User $actor, PrintJob $job, int $expectedVersion, ?string $notes): PrintJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $notes): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status !== PrintJobStatus::SampleApproved) {
                throw ApiException::conflict('sample_not_approved', 'Physical printing can start only after the printing sample is approved.');
            }
            $approved = PrintSample::query()->whereKey($job->getAttribute('approved_sample_id'))->where('print_job_id', $job->id)->first(['id', 'decision']);
            if ($approved?->decision !== SampleDecision::Approved) {
                throw ApiException::conflict('sample_not_approved', 'Physical printing can start only after the printing sample is approved.');
            }
            $this->workflow->ensureOrderStage($job, $order);

            $job->forceFill(['printing_started_by' => $actor->id, 'printing_started_at' => now()]);
            $this->workflow->transition($job, $order, PrintJobStatus::PhysicalPrinting, $actor, 'printing.physical_started', $notes);
            $this->workflow->save($job);

            return $job;
        });
    }

    public function complete(User $actor, PrintJob $job, int $expectedVersion, int $printedQuantity, ?string $notes): PrintJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $printedQuantity, $notes): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status !== PrintJobStatus::PhysicalPrinting) {
                throw ApiException::conflict('invalid_state', 'Only physical printing in progress can be completed.');
            }
            $this->workflow->ensureOrderStage($job, $order);

            $job->forceFill(['printed_quantity' => $printedQuantity, 'completion_notes' => $notes, 'printing_completed_by' => $actor->id, 'printing_completed_at' => now()]);
            $this->workflow->transition($job, $order, PrintJobStatus::PrintingCompleted, $actor, 'printing.completed', $notes,
                ['printed_quantity' => $printedQuantity, 'ordered_quantity' => $order->total_quantity]);
            $this->workflow->save($job);

            return $job;
        });
    }
}
