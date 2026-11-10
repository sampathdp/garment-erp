<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\BulkReviewDecision;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkReview;
use App\Modules\BulkProduction\Support\BulkChecklists;
use App\Modules\BulkProduction\Support\BulkNotifications;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;

/**
 * Keeps the bulk job consistent with the name/number list (called by the Garments actions,
 * inside their transaction):
 *
 *  - listReopened(): an administrative correction reopens the confirmed list. A completed bulk
 *    locks the list (409 bulk_completed). A bulk in review or approved is invalidated: a system
 *    correction is recorded against the current version, the approved list reference is
 *    released and the designer is notified. The client checklist confirmation is cleared.
 *  - listConfirmed(): the corrected list is confirmed; the job now refers to the new version.
 */
final class SyncBulkWithGarmentList
{
    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkChecklists $checklists,
        private readonly BulkNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function listReopened(User $actor, int $orderId, string $reason): void
    {
        $job = BulkJob::query()->where('order_id', $orderId)->first();
        if ($job === null) {
            return;
        }
        [$job, $order] = $this->workflow->lock($job);
        if ($job->status === BulkJobStatus::Completed) {
            throw ApiException::conflict('bulk_completed', 'The bulk for this job is completed; its name/number list is locked.');
        }
        if ($job->status === BulkJobStatus::Cancelled) {
            return;
        }

        $invalidated = in_array($job->status, [BulkJobStatus::InReview, BulkJobStatus::Approved], true);
        if ($invalidated) {
            $message = "Name/number list reopened for correction: {$reason}";
            $review = new BulkReview(['bulk_version_id' => $job->getAttribute('current_version_id'), 'decision' => BulkReviewDecision::ChangesRequested, 'reason' => mb_substr($message, 0, 1000)]);
            $review->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'is_system' => true])->save();
            $job->forceFill(['status' => BulkJobStatus::ChangesRequested, 'approved_version_id' => null, 'approved_list_version_id' => null, 'approved_by' => null, 'approved_at' => null]);
            $this->notify->correctionRequested($job, $order, $message, $actor);
        }
        $this->checklists->resetClientConfirmation($job);
        $this->workflow->save($job);
        $this->audit($actor, $job, $order, 'bulk.list_reopened', ['reason' => $reason, 'review_invalidated' => $invalidated]);
    }

    public function listConfirmed(User $actor, int $orderId, int $listVersionId): void
    {
        $job = BulkJob::query()->where('order_id', $orderId)->first();
        if ($job === null || $job->getAttribute('garment_list_version_id') === $listVersionId) {
            return;
        }
        [$job, $order] = $this->workflow->lock($job);
        if ($job->status->isClosed()) {
            return;
        }
        $from = $job->getAttribute('garment_list_version_id');
        $job->forceFill(['garment_list_version_id' => $listVersionId]);
        $this->workflow->save($job);
        $this->audit($actor, $job, $order, 'bulk.list_version_updated', ['from' => $from, 'to' => $listVersionId]);
    }

    /** @param  array<string, mixed>  $after */
    private function audit(User $actor, BulkJob $job, Order $order, string $action, array $after): void
    {
        $this->audit->record($action, $job, ['after' => $after], actor: $actor, branchId: $order->branch_id);
    }
}
