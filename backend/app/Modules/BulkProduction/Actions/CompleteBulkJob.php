<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkDocumentKind;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobDocument;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Support\BulkCompletion;
use App\Modules\BulkProduction\Support\BulkNotifications;
use App\Modules\BulkProduction\Support\BulkUploads;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Actions\OpenPrintJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Completing the bulk task — once, under the job lock (a second attempt is a 409).
 *
 *  - internal(): the assigned Bulk Designer's FINAL submission: approved bulk + final bulk file(s)
 *    + WeTransfer link, plus the common mandatory data (BulkCompletion).
 *  - external(): the Operation Admin records the external producer's completion: no designer
 *    completion and no WeTransfer link, but the same mandatory data (measurements, checklist,
 *    client confirmation, locked list, advance). Evidence is optional (addEvidence()).
 *
 * Both notify the Operation Admins and Page Owners, move the order to print preparation and
 * open the printing job (Printing OpenPrintJob).
 */
final class CompleteBulkJob
{
    public function __construct(
        private readonly BulkUploads $uploads,
        private readonly BulkWorkflow $workflow,
        private readonly BulkCompletion $completion,
        private readonly BulkNotifications $notify,
        private readonly AuditLogger $audit,
        private readonly OpenPrintJob $printing,
    ) {}

    /** @param  list<UploadedFile>  $files */
    public function internal(User $actor, BulkJob $job, int $expectedVersion, ?UploadedFile $preview, array $files, string $transferUrl, ?string $notes): BulkVersion
    {
        return $this->uploads->createVersion($actor, $job, $expectedVersion, $preview, $files, ['transfer_url' => $transferUrl, 'notes' => $notes], true,
            function (BulkJob $job, Order $order) use ($actor): void {
                if (! $job->isInternal() || $job->status !== BulkJobStatus::Approved) {
                    throw ApiException::conflict('invalid_state', $job->status === BulkJobStatus::Completed ? 'The bulk task is already completed.' : 'Final bulk files can be submitted only after the bulk is approved.');
                }
                if ((int) $job->getAttribute('bulk_designer_id') !== $actor->id) {
                    throw ApiException::forbidden('not_assignee', 'Only the assigned Bulk Designer can submit the final bulk.');
                }
                $this->workflow->ensureOrderInBulkStage($order);
                $this->completion->assertReady($job, $order);
            },
            function (BulkVersion $version, BulkJob $job, Order $order) use ($actor, $notes): void {
                $this->finish($actor, $job, $order, $notes, ['final_version_id' => $version->id, 'transfer_url' => $version->getAttribute('transfer_url')]);
            });
    }

    public function external(User $actor, BulkJob $job, int $expectedVersion, ?string $notes): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $notes): BulkJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if (! $job->isExternal() || ! in_array($job->status, [BulkJobStatus::Assigned, BulkJobStatus::InProgress], true)) {
                throw ApiException::conflict('invalid_state', $job->status === BulkJobStatus::Completed ? 'The bulk task is already completed.' : 'Only an open external bulk job can be completed here.');
            }
            $this->workflow->ensureOrderInBulkStage($order);
            $this->completion->assertReady($job, $order);
            $this->finish($actor, $job, $order, $notes, ['supplier_id' => $job->getAttribute('supplier_id')]);
            $this->workflow->save($job);

            return $job;
        });
    }

    /** External production evidence (photo / PDF), optional and repeatable while the job is open. */
    public function addEvidence(User $actor, BulkJob $job, UploadedFile $file, ?string $note): BulkJob
    {
        $stored = $this->uploads->putEvidence($file);
        try {
            return DB::transaction(function () use ($actor, $job, $stored, $note): BulkJob {
                [$job, $order] = $this->workflow->lock($job);
                if (! $job->isExternal() || ! in_array($job->status, [BulkJobStatus::Assigned, BulkJobStatus::InProgress], true)) {
                    throw ApiException::conflict('invalid_state', 'Evidence can be added only to an open external bulk job.');
                }
                $this->workflow->ensureOrderInBulkStage($order);
                $document = $this->uploads->recordEvidence($stored, $actor, $order->branch_id);
                $link = new BulkJobDocument(['bulk_job_id' => $job->id, 'document_id' => $document->id, 'kind' => BulkDocumentKind::Evidence, 'note' => $note]);
                $link->forceFill(['uploaded_by' => $actor->id])->save();

                $this->workflow->start($job, $order, $actor);
                $this->workflow->save($job);
                $this->audit->record('bulk.evidence_added', $job, ['after' => ['document_id' => $document->id, 'note' => $note]], actor: $actor, branchId: $order->branch_id);

                return $job;
            });
        } catch (Throwable $e) {
            $this->uploads->discard($stored);
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $details */
    private function finish(User $actor, BulkJob $job, Order $order, ?string $notes, array $details): void
    {
        $job->forceFill(['status' => BulkJobStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now(), 'completion_notes' => $notes]);
        $this->workflow->finishOrderStage($order, $actor);
        $this->audit->record('bulk.completed', $job, ['after' => ['mode' => $job->mode?->value, 'notes' => $notes, ...$details]], actor: $actor, branchId: $order->branch_id);
        $this->notify->completed($job, $order, $actor);
        // The printing job becomes available in the same transaction (Phase 06).
        $this->printing->handle($job, $order, $actor);
    }
}
