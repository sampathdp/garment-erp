<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\SampleDecision;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Support\PrintingNotifications;
use App\Modules\Printing\Support\PrintingWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * The Page Owner's decision on the pending printing sample (one decision per sample):
 *
 *  - approve(): records approver + time, job → sample_approved, order → printing. Physical
 *    printing may now proceed. Operation Admins are notified.
 *  - reject():  a reason is REQUIRED; records it with the reviewer and time, job →
 *    sample_rejected, order back to print_preparation; a corrected sample can be uploaded
 *    (a new version). Operation Admins are notified.
 */
final class ReviewPrintSample
{
    public function __construct(private readonly PrintingWorkflow $workflow, private readonly PrintingNotifications $notify) {}

    public function approve(User $actor, PrintJob $job, int $expectedVersion, ?ApprovalChannel $channel, ?string $comment): PrintJob
    {
        return $this->decide($job, $expectedVersion, function (PrintJob $job, Order $order, PrintSample $sample) use ($actor, $channel, $comment): void {
            $sample->forceFill(['decision' => SampleDecision::Approved, 'channel' => $channel, 'customer_comment' => $comment, 'recorded_by' => $actor->id, 'decided_at' => now()])->save();
            $job->forceFill(['approved_sample_id' => $sample->id]);
            $this->workflow->transition($job, $order, PrintJobStatus::SampleApproved, $actor, 'printing.sample_approved', $comment,
                ['sample_id' => $sample->id, 'sample_no' => $sample->sample_no, 'channel' => $channel?->value]);
            $this->notify->sampleApproved($job, $order, $sample, $actor);
        });
    }

    public function reject(User $actor, PrintJob $job, int $expectedVersion, string $reason, ?ApprovalChannel $channel): PrintJob
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new ApiException(422, 'validation_failed', 'A rejection reason is required.', ['reason' => ['Give the reason for rejecting the sample.']]);
        }

        return $this->decide($job, $expectedVersion, function (PrintJob $job, Order $order, PrintSample $sample) use ($actor, $reason, $channel): void {
            $sample->forceFill(['decision' => SampleDecision::Rejected, 'rejection_reason' => $reason, 'channel' => $channel, 'recorded_by' => $actor->id, 'decided_at' => now()])->save();
            $this->workflow->transition($job, $order, PrintJobStatus::SampleRejected, $actor, 'printing.sample_rejected', $reason,
                ['sample_id' => $sample->id, 'sample_no' => $sample->sample_no, 'channel' => $channel?->value]);
            $this->notify->sampleRejected($job, $order, $sample, $reason, $actor);
        });
    }

    /** @param  callable(PrintJob, Order, PrintSample): void  $apply */
    private function decide(PrintJob $job, int $expectedVersion, callable $apply): PrintJob
    {
        return DB::transaction(function () use ($job, $expectedVersion, $apply): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status !== PrintJobStatus::SamplePendingApproval) {
                throw ApiException::conflict('invalid_state', 'There is no printing sample awaiting approval.');
            }
            $this->workflow->ensureOrderStage($job, $order);

            $sample = PrintSample::query()->where('print_job_id', $job->id)->orderByDesc('sample_no')->lockForUpdate()->first();
            if ($sample === null || $sample->decision !== null) {
                throw ApiException::conflict('invalid_state', 'There is no printing sample awaiting approval.');
            }

            $apply($job, $order, $sample);
            $this->workflow->save($job);

            return $job;
        });
    }
}
