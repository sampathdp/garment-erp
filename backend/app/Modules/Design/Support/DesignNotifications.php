<?php

declare(strict_types=1);

namespace App\Modules\Design\Support;

use App\Modules\Design\Models\DesignTask;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;

/**
 * | Event                      | Recipients                                   |
 * |----------------------------|----------------------------------------------|
 * | design.assigned            | the assigned designer                        |
 * | design.unassigned          | users who can assign designers (in scope)    |
 * | design.submitted           | Page Owners of the page                      |
 * | design.correction_requested| the assigned designer                        |
 * | design.approved            | the assigned designer (final files needed)   |
 * | design.completed           | Page Owners of the page                      |
 */
final class DesignNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function assigned(DesignTask $task, Order $order, User $designer, ?User $actor = null): void
    {
        $this->notify->send(fn () => collect([$designer]), $this->make('design.assigned', 'New design job', "Job {$order->order_no} was assigned to you (due {$order->required_delivery_date->format('Y-m-d')}).", $task, $order), $actor);
    }

    public function unassigned(DesignTask $task, Order $order): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::DESIGNS_ASSIGN, $order->branch_id, $order->page_id),
            $this->make('design.unassigned', 'Design job needs a designer', "No responsible designer is configured. Assign a designer to job {$order->order_no}.", $task, $order));
    }

    public function submitted(DesignTask $task, Order $order, int $versionNo, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
            $this->make('design.submitted', 'Design ready for review', "Version {$versionNo} of the design for job {$order->order_no} is ready for your review.", $task, $order), $actor);
    }

    public function correctionRequested(DesignTask $task, Order $order, int $round, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $task->designer_id ? $r->users($task->designer_id) : collect(),
            $this->make('design.correction_requested', 'Design correction requested', "Correction round {$round} was requested for job {$order->order_no}.", $task, $order), $actor);
    }

    public function approved(DesignTask $task, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $task->designer_id ? $r->users($task->designer_id) : collect(),
            $this->make('design.approved', 'Design approved — upload final files', "The design for job {$order->order_no} was approved. Upload the final PDF/CDR or the final Google Drive link.", $task, $order), $actor);
    }

    public function completed(DesignTask $task, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
            $this->make('design.completed', 'Final design files received', "The final design files for job {$order->order_no} were submitted.", $task, $order), $actor);
    }

    private function make(string $event, string $title, string $message, DesignTask $task, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/designs/view/?id={$task->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'design_task_id' => $task->id]);
    }
}
