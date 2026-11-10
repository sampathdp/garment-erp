<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Support\WorkflowSettings;
use App\Support\Exceptions\ApiException;

/**
 * Creates the order's design task (once) and assigns it to the responsible Design Designer
 * configured by the Super Admin (WorkflowSettings). No designer is hard-coded. If none is
 * configured or the configured user can no longer design, the task stays unassigned and users
 * who can assign designers are notified.
 *
 * Runs inside the caller's transaction, with the order row locked.
 */
final class CreateDesignTask
{
    public function __construct(
        private readonly WorkflowSettings $settings,
        private readonly StaffDirectory $staff,
        private readonly DesignNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Order $order, User $actor): DesignTask
    {
        if (DesignTask::query()->where('order_id', $order->id)->exists()) {
            throw ApiException::conflict('design_task_exists', 'This order already has a design task.');
        }

        $item = OrderItem::query()->where('order_id', $order->id)->orderBy('sort_order')->firstOrFail();
        $designer = $this->staff->eligible($this->settings->defaultDesignerId(), Permissions::DESIGNS_UPLOAD_VERSION);

        $task = new DesignTask(['order_id' => $order->id, 'order_item_id' => $item->id, 'title' => "Design for {$order->order_no}", 'designer_id' => $designer?->id]);
        $task->forceFill(['status' => DesignTaskStatus::Pending, 'version' => 1, 'correction_rounds' => 0])->save();

        $this->audit->record('designs.task_created', $task, ['after' => ['order_id' => $order->id, 'designer_id' => $designer?->id]], actor: $actor, branchId: $order->branch_id);

        $designer !== null ? $this->notify->assigned($task, $order, $designer) : $this->notify->unassigned($task, $order);

        return $task;
    }
}
