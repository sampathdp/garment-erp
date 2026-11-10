<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Design\Support\DesignUploads;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * After the final approval, the designer submits the final PDF/CDR file(s) and/or the approved
 * final Drive link (at least one is required). This is a new, final version; only then is the
 * design task completed, and the bulk job may become eligible.
 */
final class SubmitFinalDesign
{
    public function __construct(
        private readonly DesignUploads $uploads,
        private readonly DesignNotifications $notify,
        private readonly CreateBulkJobIfEligible $bulk,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<UploadedFile>  $sources */
    public function handle(User $actor, DesignTask $task, array $sources, ?string $externalUrl, ?string $notes): DesignVersion
    {
        if ($sources === [] && ($externalUrl === null || $externalUrl === '')) {
            throw new ApiException(422, 'validation_failed', 'Upload the final PDF/CDR or enter the final Google Drive link.', ['files' => ['Upload the final PDF/CDR or enter the final Google Drive link.']]);
        }

        return $this->uploads->createVersion($actor, $task, null, $sources, $externalUrl, $notes, true,
            function (DesignTask $locked) use ($actor): void {
                if ($locked->status !== DesignTaskStatus::Approved) {
                    throw ApiException::conflict('invalid_state', 'Final files can be submitted only after the design is approved.');
                }
                if ($locked->designer_id !== $actor->id) {
                    throw ApiException::forbidden('not_assignee', 'Only the assigned designer can submit the final files.');
                }
            },
            function (DesignVersion $version, DesignTask $locked) use ($actor): void {
                $locked->forceFill(['status' => DesignTaskStatus::Completed, 'final_version_id' => $version->id, 'completed_at' => now()])->save();
                $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
                $this->audit->record('designs.completed', $locked, ['after' => ['final_version_id' => $version->id, 'external_url' => $version->external_url]], actor: $actor, branchId: $order->branch_id);
                $this->notify->completed($locked, $order, $actor);
                $this->bulk->handle($order, $actor);
            });
    }
}
