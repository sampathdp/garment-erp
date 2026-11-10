<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;

/**
 * | Event                       | Recipients                                                   |
 * |-----------------------------|--------------------------------------------------------------|
 * | bulk.job_ready (created)    | responsible Bulk Designer, Operation Admins (bulk.assign)    |
 * | bulk.assigned_internal      | the assigned Bulk Designer                                   |
 * | bulk.assigned_external      | external bulk recorders (bulk.manage_external), Page Owners  |
 * | bulk.submitted              | Page Owners of the page                                      |
 * | bulk.correction_requested   | the assigned Bulk Designer                                   |
 * | bulk.approved               | the assigned Bulk Designer                                   |
 * | bulk.completed              | Operation Admins (bulk.assign), Page Owners of the page      |
 *
 * The acting user is never notified (Notify).
 */
final class BulkNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function assignedInternal(BulkJob $job, Order $order, User $designer, User $actor): void
    {
        $this->notify->send(fn () => collect([$designer]),
            $this->make('bulk.assigned_internal', 'New bulk job', "Bulk for job {$order->order_no} was assigned to you (due {$order->required_delivery_date->format('Y-m-d')}).", $job, $order), $actor);
    }

    public function assignedExternal(BulkJob $job, Order $order, string $supplier, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::BULK_MANAGE_EXTERNAL, $order->branch_id, $order->page_id)->merge($r->pageOwners($order->page_id)),
            $this->make('bulk.assigned_external', 'Bulk assigned externally', "Bulk for job {$order->order_no} was assigned to {$supplier}.", $job, $order), $actor);
    }

    public function submitted(BulkJob $job, Order $order, int $versionNo, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
            $this->make('bulk.submitted', 'Bulk ready for review', "Bulk version {$versionNo} of job {$order->order_no} is ready for your review.", $job, $order), $actor);
    }

    public function correctionRequested(BulkJob $job, Order $order, string $reason, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $job->bulk_designer_id ? $r->users($job->bulk_designer_id) : collect(),
            $this->make('bulk.correction_requested', 'Bulk correction requested', "Correction requested for the bulk of job {$order->order_no}: {$reason}", $job, $order), $actor);
    }

    public function approved(BulkJob $job, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $job->bulk_designer_id ? $r->users($job->bulk_designer_id) : collect(),
            $this->make('bulk.approved', 'Bulk approved — submit final files', "The bulk of job {$order->order_no} was approved. Submit the final bulk files and the WeTransfer link.", $job, $order), $actor);
    }

    public function completed(BulkJob $job, Order $order, User $actor): void
    {
        $how = $job->isExternal() ? 'External bulk' : 'Bulk';
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::BULK_ASSIGN, $order->branch_id, $order->page_id)->merge($r->pageOwners($order->page_id)),
            $this->make('bulk.completed', 'Bulk completed', "{$how} for job {$order->order_no} is complete. The job moved to print preparation.", $job, $order), $actor);
    }

    private function make(string $event, string $title, string $message, BulkJob $job, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/bulk-jobs/view/?id={$job->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'bulk_job_id' => $job->id]);
    }
}
