<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Support;

use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;

/**
 * | Event               | Recipients                                              |
 * |---------------------|---------------------------------------------------------|
 * | cutting.job_ready   | Operation Admins (cutting.manage) in scope              |
 * | cutting.assigned    | The assigned cutter                                     |
 * | cutting.completed   | Operation Admins (cutting.manage) in scope, Page Owners |
 *
 * The acting user is never notified (Notify).
 */
final class CuttingNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function ready(CuttingJob $job, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::CUTTING_MANAGE, $order->branch_id, $order->page_id),
            $this->make('cutting.job_ready', 'Job ready for cutting', "Job {$order->order_no} is printed and its gate pass is recorded. Assign a cutter.", $job, $order), $actor);
    }

    public function assigned(CuttingJob $job, Order $order, User $cutter, User $actor): void
    {
        $due = $job->expected_completion_date?->format('Y-m-d');
        $this->notify->send(fn (Recipients $r) => $r->users($cutter->id),
            $this->make('cutting.assigned', 'Cutting job assigned to you', "Job {$order->order_no} was assigned to you for cutting".($due === null ? '.' : " (expected by {$due})."), $job, $order), $actor);
    }

    public function completed(CuttingJob $job, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::CUTTING_MANAGE, $order->branch_id, $order->page_id)->merge($r->pageOwners($order->page_id)),
            $this->make('cutting.completed', 'Cutting completed', "Cutting of job {$order->order_no} is completed. The job moves on to sewing.", $job, $order), $actor);
    }

    private function make(string $event, string $title, string $message, CuttingJob $job, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/cutting/view/?id={$job->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'cutting_job_id' => $job->id]);
    }
}
