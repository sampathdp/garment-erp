<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Support\BulkNotifications;
use App\Modules\BulkProduction\Support\BulkUploads;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * The assigned Bulk Designer submits the prepared bulk (review files) for the Page Owner's review.
 * Every submission is a NEW immutable version linked to the previous one and to the confirmed
 * list version it was prepared from; earlier versions and their decisions are kept.
 */
final class SubmitBulkForReview
{
    public function __construct(
        private readonly BulkUploads $uploads,
        private readonly BulkWorkflow $workflow,
        private readonly BulkNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<UploadedFile>  $files */
    public function handle(User $actor, BulkJob $job, int $expectedVersion, ?UploadedFile $preview, array $files, ?string $externalUrl, ?string $notes): BulkVersion
    {
        return $this->uploads->createVersion($actor, $job, $expectedVersion, $preview, $files, ['external_url' => $externalUrl, 'notes' => $notes], false,
            function (BulkJob $job, Order $order) use ($actor): void {
                if (! $job->isInternal() || ! in_array($job->status, [BulkJobStatus::Assigned, BulkJobStatus::InProgress, BulkJobStatus::ChangesRequested], true)) {
                    throw ApiException::conflict('invalid_state', 'The bulk cannot be submitted for review in its current state.');
                }
                if ((int) $job->getAttribute('bulk_designer_id') !== $actor->id) {
                    throw ApiException::forbidden('not_assignee', 'Only the assigned Bulk Designer can submit the bulk.');
                }
                $this->workflow->ensureOrderInBulkStage($order);
                if (! $this->workflow->listIsCurrentAndLocked($job)) {
                    throw ApiException::conflict('list_not_locked', 'The name/number list is being corrected. Wait until it is confirmed again.');
                }
            },
            function (BulkVersion $version, BulkJob $job, Order $order) use ($actor): void {
                $this->workflow->start($job, $order, $actor);
                $job->forceFill(['status' => BulkJobStatus::InReview]);
                $this->audit->record('bulk.submitted_for_review', $version, ['after' => [
                    'bulk_job_id' => $job->id, 'version_no' => $version->version_no, 'previous_version_id' => $version->getAttribute('previous_version_id'),
                    'garment_list_version_id' => $version->getAttribute('garment_list_version_id'),
                ]], actor: $actor, branchId: $order->branch_id);
                $this->notify->submitted($job, $order, $version->version_no, $actor);
            });
    }
}
