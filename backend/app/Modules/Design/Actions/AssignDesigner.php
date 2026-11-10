<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/** (Re)assigns a design task to an active user who can design (designs.upload_version). */
final class AssignDesigner
{
    public function __construct(private readonly StaffDirectory $staff, private readonly DesignNotifications $notify, private readonly AuditLogger $audit) {}

    public function handle(User $actor, DesignTask $task, int $designerId): DesignTask
    {
        $designer = $this->staff->eligible($designerId, Permissions::DESIGNS_UPLOAD_VERSION)
            ?? throw new ApiException(422, 'validation_failed', 'Select an active designer.', ['designer_id' => ['Select an active user who can upload designs.']]);

        return DB::transaction(function () use ($actor, $task, $designer): DesignTask {
            $task = DesignTask::query()->lockForUpdate()->findOrFail($task->id);
            $before = $task->designer_id;
            if ($before === $designer->id) {
                return $task;
            }
            $task->forceFill(['designer_id' => $designer->id, 'version' => $task->version + 1])->save();
            $order = Order::query()->findOrFail($task->order_id);
            $this->audit->record('designs.assigned', $task, ['before' => ['designer_id' => $before], 'after' => ['designer_id' => $designer->id]], actor: $actor, branchId: $order->branch_id);
            $this->notify->assigned($task, $order, $designer, $actor);

            return $task;
        });
    }
}
