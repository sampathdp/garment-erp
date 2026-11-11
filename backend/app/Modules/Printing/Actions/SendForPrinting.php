<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Support\PrintingNotifications;
use App\Modules\Printing\Support\PrintingWorkflow;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * The Operation Admin reviewed the printing information and sends the job for printing:
 * records who, when, the printing company / recipient (a registered supplier and/or a name)
 * and notes. Page Owners are notified.
 */
final class SendForPrinting
{
    public function __construct(private readonly PrintingWorkflow $workflow, private readonly PrintingNotifications $notify) {}

    public function handle(User $actor, PrintJob $job, int $expectedVersion, ?int $supplierId, ?string $recipient, ?string $notes): PrintJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $supplierId, $recipient, $notes): PrintJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            $this->workflow->ensureOrderStage($job, $order);

            $supplier = null;
            if ($supplierId !== null) {
                $supplier = Supplier::query()->whereKey($supplierId)->where('is_active', true)->first(['id', 'name']);
                if ($supplier === null) {
                    throw new ApiException(422, 'validation_failed', 'The printing company is not an active supplier.', ['supplier_id' => ['Choose an active supplier.']]);
                }
            }

            $job->forceFill(['supplier_id' => $supplier?->id, 'recipient_name' => $recipient, 'send_notes' => $notes, 'sent_by' => $actor->id, 'sent_at' => now()]);
            $this->workflow->transition($job, $order, PrintJobStatus::SentForPrinting, $actor, 'printing.sent', $notes,
                ['supplier_id' => $supplier?->id, 'supplier' => $supplier?->name, 'recipient_name' => $recipient]);
            $this->workflow->save($job);
            $this->notify->sent($job, $order, $actor);

            return $job;
        });
    }
}
