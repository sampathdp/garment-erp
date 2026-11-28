<?php

declare(strict_types=1);

namespace App\Modules\Design\Support;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Organizations\Support\WorkflowSettings;

/**
 * Direct-to-bulk design mode (WorkflowSettings::DESIGN_DIRECT_TO_BULK, on by default): the
 * designer's upload is the final design — no Page Owner review — and the bulk job is created as
 * soon as the list is locked and the advance verified. Until the bulk designer starts work, the
 * designer may upload a replacement (customer change); the bulk job always uses the latest one.
 */
final class DirectDesign
{
    /** Bulk statuses in which no bulk work has started yet. */
    private const NOT_STARTED = [BulkJobStatus::PendingAssignment, BulkJobStatus::Assigned];

    public function __construct(private readonly WorkflowSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->designDirectToBulk();
    }

    /** A completed direct design may be replaced while its bulk job has not started. */
    public function canReplace(DesignTask $task): bool
    {
        if ($task->status !== DesignTaskStatus::Completed || ! $this->enabled()) {
            return false;
        }
        $job = BulkJob::query()->where('order_id', $task->getAttribute('order_id'))->first(['id', 'status']);

        return $job === null || in_array($job->status, self::NOT_STARTED, true);
    }

    public function bulkJob(DesignTask $task): ?BulkJob
    {
        return BulkJob::query()->where('order_id', $task->getAttribute('order_id'))->first();
    }
}
