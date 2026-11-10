<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkDocumentKind;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobDocument;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Support\BulkChecklists;
use App\Modules\BulkProduction\Support\BulkUploads;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The job's checklist instance.
 *
 *  - updateItem(): mark an item completed / not completed (who and when are recorded) and keep notes.
 *  - confirmWithClient(): the Page Owner records that the customer confirmed the final checklist
 *    (channel, comment, optional evidence). Only when every mandatory item is completed; the
 *    checklist is then locked. A bulk correction request or a list correction clears it.
 */
final class UpdateBulkChecklist
{
    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkChecklists $checklists,
        private readonly BulkUploads $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function updateItem(User $actor, BulkJob $job, int $itemId, ?bool $completed, ?string $notes, bool $notesGiven): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $itemId, $completed, $notes, $notesGiven): BulkJob {
            [$job, $order] = $this->workflow->lock($job);
            if ($job->mode === null || ! $job->status->acceptsWork($job->mode)) {
                throw ApiException::conflict('invalid_state', 'The checklist cannot be changed in the current state of the bulk job.');
            }
            $this->workflow->ensureOrderInBulkStage($order);

            $run = $this->checklists->ensure($job) ?? throw ApiException::conflict('checklist_missing', 'No checklist template is configured for this job.');
            if ($run->isClientConfirmed()) {
                throw ApiException::conflict('checklist_confirmed', 'The client already confirmed this checklist; it can no longer be changed.');
            }
            $item = ChecklistRunItem::query()->where('checklist_run_id', $run->id)->lockForUpdate()->find($itemId)
                ?? throw new ApiException(404, 'not_found', 'Checklist item not found.');

            $before = ['is_completed' => $item->is_completed, 'note' => $item->getAttribute('note')];
            if ($completed !== null && $completed !== $item->is_completed) {
                $item->forceFill($completed
                    ? ['is_completed' => true, 'completed_by' => $actor->id, 'completed_at' => now()]
                    : ['is_completed' => false, 'completed_by' => null, 'completed_at' => null]);
            }
            if ($notesGiven) {
                $item->forceFill(['note' => $notes]);
            }
            if (! $item->isDirty()) {
                return $job;
            }
            $item->save();

            $this->workflow->start($job, $order, $actor);
            $this->workflow->save($job);
            $this->audit->record('bulk.checklist_item_updated', $job, ['before' => [...$before, 'item' => $item->label],
                'after' => ['item' => $item->label, 'is_completed' => $item->is_completed, 'note' => $item->getAttribute('note')]], actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }

    public function confirmWithClient(User $actor, BulkJob $job, ApprovalChannel $channel, ?string $comment, ?UploadedFile $evidence): BulkJob
    {
        $stored = $evidence === null ? null : $this->uploads->putEvidence($evidence);
        try {
            return DB::transaction(function () use ($actor, $job, $channel, $comment, $stored): BulkJob {
                [$job, $order] = $this->workflow->lock($job);
                if ($job->mode === null || $job->status->isClosed()) {
                    throw ApiException::conflict('invalid_state', 'The checklist cannot be confirmed in the current state of the bulk job.');
                }
                $run = $this->checklists->ensure($job) ?? throw ApiException::conflict('checklist_missing', 'No checklist template is configured for this job.');
                if ($run->isClientConfirmed()) {
                    throw ApiException::conflict('checklist_already_confirmed', 'The client already confirmed this checklist.');
                }
                $open = $this->checklists->openMandatory($run);
                if ($open !== []) {
                    throw new ApiException(409, 'checklist_incomplete', 'Complete every mandatory checklist item before recording the client confirmation.', ['checklist' => array_map(fn (string $l) => "Not completed: {$l}", $open)]);
                }

                $documentId = null;
                if ($stored !== null) {
                    $document = $this->uploads->recordEvidence($stored, $actor, $order->branch_id);
                    $link = new BulkJobDocument(['bulk_job_id' => $job->id, 'document_id' => $document->id, 'kind' => BulkDocumentKind::ClientConfirmation, 'note' => 'Client checklist confirmation']);
                    $link->forceFill(['uploaded_by' => $actor->id])->save();
                    $documentId = $document->id;
                }
                $run->forceFill(['client_confirmed_at' => now(), 'client_confirmed_by' => $actor->id, 'client_channel' => $channel, 'client_comment' => $comment, 'client_evidence_document_id' => $documentId])->save();
                $this->workflow->save($job);
                $this->audit->record('bulk.checklist_client_confirmed', $job, ['after' => ['channel' => $channel->value, 'comment' => $comment, 'evidence_document_id' => $documentId]], actor: $actor, branchId: $order->branch_id);

                return $job;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->uploads->discard($stored);
            }
            throw $e;
        }
    }
}
