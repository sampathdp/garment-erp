<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\BulkReviewDecision;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkReview;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Support\BulkChecklists;
use App\Modules\BulkProduction\Support\BulkNotifications;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Page Owner review of the submitted bulk version (policy: bulk.approve + Page Owner of the page).
 *
 *  - requestCorrection(): reason required; a numbered correction round is recorded against the
 *    version, the designer is notified and can submit a revised version. The client checklist
 *    confirmation is cleared (the client confirms the corrected bulk again).
 *  - approve(): records the approval and LOCKS the approved list reference (the confirmed list
 *    version the approved bulk was prepared from); the designer is notified to submit the final
 *    files and the WeTransfer link.
 */
final class ReviewBulk
{
    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkChecklists $checklists,
        private readonly BulkNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function requestCorrection(User $actor, BulkJob $job, int $expectedVersion, string $reason): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $reason): BulkJob {
            [$job, $order, $version] = $this->lockInReview($job, $expectedVersion);

            $round = $job->correction_rounds + 1;
            $this->review($version, $actor, BulkReviewDecision::ChangesRequested, $reason, $round);
            $job->forceFill(['status' => BulkJobStatus::ChangesRequested, 'correction_rounds' => $round]);
            $this->workflow->save($job);
            $this->checklists->resetClientConfirmation($job);

            $this->audit->record('bulk.correction_requested', $job, ['after' => ['version_no' => $version->version_no, 'round' => $round, 'reason' => $reason]], actor: $actor, branchId: $order->branch_id);
            $this->notify->correctionRequested($job, $order, $reason, $actor);

            return $job;
        });
    }

    public function approve(User $actor, BulkJob $job, int $expectedVersion, ?string $comment): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $comment): BulkJob {
            [$job, $order, $version] = $this->lockInReview($job, $expectedVersion);
            if ($version->getAttribute('garment_list_version_id') !== $job->getAttribute('garment_list_version_id') || ! $this->workflow->listIsCurrentAndLocked($job)) {
                throw ApiException::conflict('list_changed', 'The name/number list changed after this bulk was prepared. Request a correction instead.');
            }

            $this->review($version, $actor, BulkReviewDecision::Approved, $comment, null);
            $job->forceFill([
                'status' => BulkJobStatus::Approved, 'approved_version_id' => $version->id,
                'approved_list_version_id' => $version->getAttribute('garment_list_version_id'), 'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
            $this->workflow->save($job);

            $this->audit->record('bulk.approved', $job, ['after' => [
                'approved_version_id' => $version->id, 'version_no' => $version->version_no,
                'approved_list_version_id' => $version->getAttribute('garment_list_version_id'), 'comment' => $comment,
            ]], actor: $actor, branchId: $order->branch_id);
            $this->notify->approved($job, $order, $actor);

            return $job;
        });
    }

    /** @return array{0: BulkJob, 1: Order, 2: BulkVersion} */
    private function lockInReview(BulkJob $job, int $expectedVersion): array
    {
        [$job, $order] = $this->workflow->lock($job, $expectedVersion);
        if ($job->status !== BulkJobStatus::InReview || $job->getAttribute('current_version_id') === null) {
            throw ApiException::conflict('invalid_state', 'This bulk is not waiting for review.');
        }
        $this->workflow->ensureOrderInBulkStage($order);

        return [$job, $order, BulkVersion::query()->findOrFail($job->getAttribute('current_version_id'))];
    }

    private function review(BulkVersion $version, User $actor, BulkReviewDecision $decision, ?string $reason, ?int $round, bool $system = false): BulkReview
    {
        $review = new BulkReview(['bulk_version_id' => $version->id, 'decision' => $decision, 'reason' => $reason, 'round_no' => $round]);
        $review->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'is_system' => $system])->save();

        return $review;
    }
}
