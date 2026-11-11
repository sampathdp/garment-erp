<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintSample;
use Illuminate\Support\Collection;

/**
 * | Event                         | Recipients                                                 |
 * |-------------------------------|------------------------------------------------------------|
 * | printing.job_ready            | Operation Admins (printing.manage) in scope                |
 * | printing.sent                 | Page Owners of the page                                    |
 * | printing.sample_uploaded      | Page Owners of the page                                    |
 * | printing.sample_approved      | Operation Admins (printing.manage), the sample uploader    |
 * | printing.sample_rejected      | Operation Admins (printing.manage), the sample uploader    |
 *
 * The acting user is never notified (Notify).
 */
final class PrintingNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function ready(PrintJob $job, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::PRINTING_MANAGE, $order->branch_id, $order->page_id),
            $this->make('printing.job_ready', 'Job ready for printing', "Bulk for job {$order->order_no} is complete. The job is ready for printing.", $job, $order), $actor);
    }

    public function sent(PrintJob $job, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
            $this->make('printing.sent', 'Job sent for printing', "Job {$order->order_no} was sent for printing.", $job, $order), $actor);
    }

    public function sampleUploaded(PrintJob $job, Order $order, PrintSample $sample, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
            $this->make('printing.sample_uploaded', 'Printing sample awaiting approval', "Printing sample {$sample->sample_no} of job {$order->order_no} is waiting for your approval.", $job, $order), $actor);
    }

    public function sampleApproved(PrintJob $job, Order $order, PrintSample $sample, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $this->operations($r, $order, $sample),
            $this->make('printing.sample_approved', 'Printing sample approved', "Printing sample {$sample->sample_no} of job {$order->order_no} was approved. Physical printing can proceed.", $job, $order), $actor);
    }

    public function sampleRejected(PrintJob $job, Order $order, PrintSample $sample, string $reason, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $this->operations($r, $order, $sample),
            $this->make('printing.sample_rejected', 'Printing sample rejected', "Printing sample {$sample->sample_no} of job {$order->order_no} was rejected: {$reason}", $job, $order), $actor);
    }

    /** @return Collection<int, User> */
    private function operations(Recipients $r, Order $order, PrintSample $sample): Collection
    {
        $uploader = $sample->getAttribute('uploaded_by');

        return $r->withPermission(Permissions::PRINTING_MANAGE, $order->branch_id, $order->page_id)
            ->merge($uploader === null ? [] : $r->users((int) $uploader));
    }

    private function make(string $event, string $title, string $message, PrintJob $job, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/printing/view/?id={$job->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'print_job_id' => $job->id]);
    }
}
