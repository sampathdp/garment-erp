<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Design\Support\DesignUploads;
use App\Modules\Design\Support\DirectDesign;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * Designer submits a design: always a NEW version linked to the previous one; an open correction
 * request is marked as resolved by it.
 *
 *  - Review mode: the task goes to review and the order to design_review (Page Owner approves,
 *    then the designer submits the final files with SubmitFinalDesign).
 *  - Direct-to-bulk mode (DirectDesign, default): the upload must contain the PDF/CDR file(s) or
 *    the Drive link and IS the final design. The task is completed, the order moves to
 *    design_approved and the bulk job is created once the list is locked and the advance verified.
 *    Until bulk work starts the designer may upload a replacement; the bulk job uses the latest.
 */
final class SubmitDesignVersion
{
    private const WORKABLE = [DesignTaskStatus::Pending, DesignTaskStatus::InProgress, DesignTaskStatus::ChangesRequested];

    public function __construct(
        private readonly DesignUploads $uploads,
        private readonly TransitionOrder $transition,
        private readonly DesignNotifications $notify,
        private readonly DirectDesign $direct,
        private readonly CreateBulkJobIfEligible $bulk,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<UploadedFile>  $sources */
    public function handle(User $actor, DesignTask $task, ?UploadedFile $preview, array $sources, ?string $externalUrl, ?string $notes): DesignVersion
    {
        $direct = $this->direct->enabled();
        if ($direct && $sources === [] && ($externalUrl === null || $externalUrl === '')) {
            throw new ApiException(422, 'validation_failed', 'Upload the PDF/CDR file(s) or enter the Google Drive link. The design goes straight to bulk.', [
                'files' => ['Upload the PDF/CDR file(s) or enter the Google Drive link.'],
            ]);
        }

        $order = null;
        $replacing = false;

        return $this->uploads->createVersion($actor, $task, $preview, $sources, $externalUrl, $notes, $direct,
            function (DesignTask $locked) use (&$order, &$replacing, $actor, $direct): void {
                if ($locked->designer_id !== $actor->id) {
                    throw ApiException::forbidden('not_assignee', 'Only the assigned designer can submit versions.');
                }
                $replacing = $direct && $this->direct->canReplace($locked);
                if (! in_array($locked->status, self::WORKABLE, true) && ! $replacing) {
                    throw ApiException::conflict('invalid_state', 'New versions cannot be uploaded while the design is in review or approved, or once bulk work has started.');
                }
                $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            },
            function (DesignVersion $version, DesignTask $locked) use (&$order, &$replacing, $actor, $direct): void {
                /** @var Order $order */
                DesignCorrectionRequest::query()->where('design_task_id', $locked->id)->whereNull('resolved_in_version_id')
                    ->update(['resolved_in_version_id' => $version->id, 'updated_at' => now()]);

                if ($direct) {
                    $this->completeDirect($actor, $locked, $order, $version, $replacing);

                    return;
                }

                $locked->forceFill(['status' => DesignTaskStatus::InReview])->save();
                if ($order->status === OrderStatus::DesignInProgress) {
                    $this->transition->handle($order, OrderStatus::DesignReview, $actor, "Design version {$version->version_no} submitted.");
                }
                $this->audit->record('designs.version_submitted', $version, ['after' => [
                    'design_task_id' => $locked->id, 'version_no' => $version->version_no, 'previous_version_id' => $version->previous_version_id,
                    'external_url' => $version->external_url,
                ]], actor: $actor, branchId: $order->branch_id);
                $this->notify->submitted($locked, $order, $version->version_no, $actor);
            });
    }

    private function completeDirect(User $actor, DesignTask $task, Order $order, DesignVersion $version, bool $replacing): void
    {
        $previousFinal = $task->getAttribute('final_version_id');
        $task->forceFill([
            'status' => DesignTaskStatus::Completed,
            'approved_version_id' => $version->id,
            'final_version_id' => $version->id,
            'completed_at' => now(),
        ])->save();

        if (in_array($order->status, [OrderStatus::DesignInProgress, OrderStatus::DesignReview], true)) {
            $this->transition->handle($order, OrderStatus::DesignApproved, $actor, "Design version {$version->version_no} submitted; sent directly to bulk (no review).");
        }

        $this->audit->record('designs.completed', $task, ['after' => [
            'final_version_id' => $version->id, 'version_no' => $version->version_no, 'external_url' => $version->external_url,
            'direct_to_bulk' => true, 'replaced_version_id' => $replacing ? $previousFinal : null,
        ]], actor: $actor, branchId: $order->branch_id);

        $this->notify->completed($task, $order, $actor);
        $bulkJob = $this->direct->bulkJob($task);
        if ($replacing && $bulkJob !== null && $bulkJob->getAttribute('bulk_designer_id') !== null) {
            $this->notify->replacedForBulk($task, $order, (int) $bulkJob->getAttribute('bulk_designer_id'), $version->version_no, $actor);
        }

        $this->bulk->handle($order, $actor);
    }
}
