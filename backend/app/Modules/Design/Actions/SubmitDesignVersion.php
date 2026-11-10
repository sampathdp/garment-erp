<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Design\Support\DesignUploads;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * Designer submits a design (initial or revised): always a NEW version linked to the previous
 * one. The task goes to review and the order to design_review; an open correction request is
 * marked as resolved by this version.
 */
final class SubmitDesignVersion
{
    public function __construct(
        private readonly DesignUploads $uploads,
        private readonly TransitionOrder $transition,
        private readonly DesignNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<UploadedFile>  $sources */
    public function handle(User $actor, DesignTask $task, ?UploadedFile $preview, array $sources, ?string $externalUrl, ?string $notes): DesignVersion
    {
        $order = null;

        $version = $this->uploads->createVersion($actor, $task, $preview, $sources, $externalUrl, $notes, false,
            function (DesignTask $locked) use (&$order, $actor): void {
                if (! in_array($locked->status, [DesignTaskStatus::Pending, DesignTaskStatus::InProgress, DesignTaskStatus::ChangesRequested], true)) {
                    throw ApiException::conflict('invalid_state', 'New versions cannot be uploaded while the design is in review or approved.');
                }
                if ($locked->designer_id !== $actor->id) {
                    throw ApiException::forbidden('not_assignee', 'Only the assigned designer can submit versions.');
                }
                $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            },
            function (DesignVersion $version, DesignTask $locked) use (&$order, $actor): void {
                /** @var Order $order */
                DesignCorrectionRequest::query()->where('design_task_id', $locked->id)->whereNull('resolved_in_version_id')
                    ->update(['resolved_in_version_id' => $version->id, 'updated_at' => now()]);
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

        return $version;
    }
}
