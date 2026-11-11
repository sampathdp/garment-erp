<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobGatePass;
use App\Modules\Printing\Support\PrintingWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records gate pass details after physical printing: number, date, optional scanned document,
 * recorder. Append-only; a gate pass number is recorded once per printing job (409 otherwise).
 */
final class RecordPrintGatePass
{
    public function __construct(private readonly StoreDocument $documents, private readonly PrintingWorkflow $workflow, private readonly AuditLogger $audit) {}

    public function handle(User $actor, PrintJob $job, string $number, Carbon $date, ?UploadedFile $document, ?string $notes): PrintJobGatePass
    {
        $stored = $document === null ? null : $this->documents->put($document, DocumentCategory::GatePass);
        try {
            return DB::transaction(function () use ($actor, $job, $number, $date, $stored, $notes): PrintJobGatePass {
                [$job, $order] = $this->workflow->lock($job);
                if ($job->status !== PrintJobStatus::PrintingCompleted) {
                    throw ApiException::conflict('invalid_state', 'Gate pass details can be recorded only after physical printing is completed.');
                }
                if (PrintJobGatePass::query()->where('print_job_id', $job->id)->where('gate_pass_no', $number)->exists()) {
                    throw ApiException::conflict('duplicate_gate_pass', "Gate pass {$number} is already recorded for this job.");
                }

                $documentId = $stored === null ? null : $this->documents->record($stored, DocumentCategory::GatePass, $actor, $order->branch_id)->id;
                $pass = new PrintJobGatePass(['print_job_id' => $job->id, 'gate_pass_no' => $number, 'gate_pass_date' => $date->toDateString(), 'document_id' => $documentId, 'notes' => $notes]);
                $pass->forceFill(['recorded_by' => $actor->id])->save();

                $this->audit->record('printing.gate_pass_recorded', $job, ['after' => [
                    'gate_pass_id' => $pass->id, 'gate_pass_no' => $number, 'gate_pass_date' => $date->toDateString(), 'document_id' => $documentId,
                ]], actor: $actor, branchId: $order->branch_id);
                $this->workflow->save($job);

                return $pass;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            throw $e;
        }
    }
}
