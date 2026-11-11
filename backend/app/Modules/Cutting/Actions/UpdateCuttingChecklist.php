<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\ChecklistResult;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Support\CuttingChecklists;
use App\Modules\Cutting\Support\CuttingWorkflow;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The cutting job's checklist instances.
 *
 *  - updateItem(): a cutting task — completed / not completed (who and when are recorded),
 *    responsible user and notes. Locked once the client confirmed the checklist.
 *  - recordQualityCheck(): a quality check — pass / fail / n/a; fail and n/a need a note.
 *  - confirmWithClient(): the Page Owner records that the customer confirmed the final cutting
 *    checklist (channel, comment, optional evidence) — only when every mandatory task is done.
 */
final class UpdateCuttingChecklist
{
    public function __construct(
        private readonly CuttingWorkflow $workflow,
        private readonly CuttingChecklists $checklists,
        private readonly StoreDocument $documents,
        private readonly AuditLogger $audit,
    ) {}

    public function updateItem(User $actor, CuttingJob $job, int $itemId, ?bool $completed, ?string $notes, bool $notesGiven, ?int $responsibleId, bool $responsibleGiven): CuttingJob
    {
        return DB::transaction(function () use ($actor, $job, $itemId, $completed, $notes, $notesGiven, $responsibleId, $responsibleGiven): CuttingJob {
            [$job, $order] = $this->workflow->lock($job);
            $this->workflow->ensureAcceptsWork($job);
            $this->workflow->ensureOrderStage($order);

            $run = $this->run($job, ChecklistStage::Cutting);
            if ($run->isClientConfirmed()) {
                throw ApiException::conflict('checklist_confirmed', 'The client already confirmed this checklist; it can no longer be changed.');
            }
            $item = $this->item($run, $itemId);
            if ($responsibleGiven && $responsibleId !== null) {
                $this->assertResponsible($responsibleId, $order);
            }

            $before = ['is_completed' => $item->is_completed, 'note' => $item->getAttribute('note'), 'responsible_user_id' => $item->getAttribute('responsible_user_id')];
            if ($completed !== null && $completed !== $item->is_completed) {
                $item->forceFill($completed
                    ? ['is_completed' => true, 'completed_by' => $actor->id, 'completed_at' => now()]
                    : ['is_completed' => false, 'completed_by' => null, 'completed_at' => null]);
            }
            if ($notesGiven) {
                $item->forceFill(['note' => $notes]);
            }
            if ($responsibleGiven) {
                $item->forceFill(['responsible_user_id' => $responsibleId]);
            }
            if (! $item->isDirty()) {
                return $job;
            }
            $item->save();

            $this->workflow->start($job, $order, $actor);
            $this->workflow->save($job);
            $this->audit->record('cutting.checklist_item_updated', $job, ['before' => [...$before, 'item' => $item->label],
                'after' => ['item' => $item->label, 'is_completed' => $item->is_completed, 'note' => $item->getAttribute('note'), 'responsible_user_id' => $item->getAttribute('responsible_user_id')]],
                actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }

    public function recordQualityCheck(User $actor, CuttingJob $job, int $itemId, ChecklistResult $result, ?string $note): CuttingJob
    {
        if ($result !== ChecklistResult::Pass && ($note === null || mb_strlen($note) < 3)) {
            throw new ApiException(422, 'validation_failed', 'A note is required when a quality check fails or is not applicable.', ['note' => ['A note is required when a quality check fails or is not applicable.']]);
        }

        return DB::transaction(function () use ($actor, $job, $itemId, $result, $note): CuttingJob {
            [$job, $order] = $this->workflow->lock($job);
            $this->workflow->ensureAcceptsWork($job);
            $this->workflow->ensureOrderStage($order);

            $item = $this->item($this->run($job, ChecklistStage::QualityControl), $itemId);
            $before = ['result' => $item->result?->value, 'note' => $item->getAttribute('note')];
            $item->forceFill(['result' => $result, 'note' => $note, 'is_completed' => true, 'completed_by' => $actor->id, 'completed_at' => now()])->save();

            $this->workflow->start($job, $order, $actor);
            $this->workflow->save($job);
            $this->audit->record('cutting.quality_check_recorded', $job, ['before' => [...$before, 'check' => $item->label],
                'after' => ['check' => $item->label, 'result' => $result->value, 'note' => $note, 'is_required' => $item->is_required]], actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }

    public function confirmWithClient(User $actor, CuttingJob $job, ApprovalChannel $channel, ?string $comment, ?UploadedFile $evidence): CuttingJob
    {
        $stored = $evidence === null ? null : $this->documents->put($evidence, DocumentCategory::Evidence);
        try {
            return DB::transaction(function () use ($actor, $job, $channel, $comment, $stored): CuttingJob {
                [$job, $order] = $this->workflow->lock($job);
                $this->workflow->ensureAcceptsWork($job);
                $this->workflow->ensureOrderStage($order);

                $run = $this->run($job, ChecklistStage::Cutting);
                if ($run->isClientConfirmed()) {
                    throw ApiException::conflict('checklist_already_confirmed', 'The client already confirmed this checklist.');
                }
                $open = $this->checklists->openMandatory($run);
                if ($open !== []) {
                    throw new ApiException(409, 'checklist_incomplete', 'Complete every mandatory cutting task before recording the client confirmation.', ['checklist' => array_map(fn (string $l) => "Not completed: {$l}", $open)]);
                }

                $documentId = $stored === null ? null : $this->documents->record($stored, DocumentCategory::Evidence, $actor, $order->branch_id)->id;
                $run->forceFill(['client_confirmed_at' => now(), 'client_confirmed_by' => $actor->id, 'client_channel' => $channel, 'client_comment' => $comment,
                    'client_evidence_document_id' => $documentId, 'completed_by' => $actor->id, 'completed_at' => now()])->save();
                $this->workflow->save($job);
                $this->audit->record('cutting.checklist_client_confirmed', $job, ['after' => ['channel' => $channel->value, 'comment' => $comment, 'evidence_document_id' => $documentId]], actor: $actor, branchId: $order->branch_id);

                return $job;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            throw $e;
        }
    }

    private function run(CuttingJob $job, ChecklistStage $stage): ChecklistRun
    {
        return $this->checklists->ensure($job, $stage) ?? throw ApiException::conflict('checklist_missing', $stage === ChecklistStage::Cutting
            ? 'No cutting checklist is configured for this job.' : 'No quality checks are configured for this job.');
    }

    private function item(ChecklistRun $run, int $itemId): ChecklistRunItem
    {
        return ChecklistRunItem::query()->where('checklist_run_id', $run->id)->lockForUpdate()->find($itemId)
            ?? throw new ApiException(404, 'not_found', 'Checklist item not found.');
    }

    private function assertResponsible(int $userId, Order $order): void
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();
        $ok = $user !== null && ($user->can(Permissions::CUTTING_UPDATE_CHECKLIST) || $user->can(Permissions::CUTTING_MANAGE))
            && $user->canAccessBranch($order->branch_id) && $user->canAccessPage($order->page_id);
        if (! $ok) {
            throw new ApiException(422, 'validation_failed', 'Choose an active cutting user who can work on this job.', ['responsible_user_id' => ['Choose an active cutting user who can work on this job.']]);
        }
    }
}
