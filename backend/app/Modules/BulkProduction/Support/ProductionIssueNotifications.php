<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;

/**
 * | Event                  | Recipients                                                                  |
 * |------------------------|-----------------------------------------------------------------------------|
 * | issues.reported        | Operation Admins (production.issues.manage) in scope; for bulk-related      |
 * |                        | categories also the order's Bulk Designer                                   |
 * | issues.assigned        | The assignee                                                                |
 * | issues.status_changed  | The reporter and the assignee; issue managers when it is resolved; the      |
 * |                        | Bulk Designer when a bulk-related issue needs correction                    |
 *
 * The acting user is never notified (Notify).
 */
final class ProductionIssueNotifications
{
    public function __construct(private readonly Notify $notify) {}

    public function reported(ProductionIssue $issue, Order $order, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->withPermission(Permissions::ISSUES_MANAGE, $order->branch_id, $order->page_id)
            ->merge($issue->type->isBulkRelated() ? $this->bulkDesigner($r, $order) : []),
            $this->make('issues.reported', 'Production issue reported', "{$issue->type->label()} on job {$order->order_no}: ".$this->excerpt($issue), $issue, $order), $actor);
    }

    public function assigned(ProductionIssue $issue, Order $order, User $assignee, User $actor): void
    {
        $this->notify->send(fn (Recipients $r) => $r->users($assignee->id),
            $this->make('issues.assigned', 'Production issue assigned to you', "Issue #{$issue->id} ({$issue->type->label()}) on job {$order->order_no} was assigned to you.", $issue, $order), $actor);
    }

    public function statusChanged(ProductionIssue $issue, Order $order, User $actor): void
    {
        $this->notify->send(function (Recipients $r) use ($issue, $order): Collection {
            $ids = array_filter([$issue->raised_by, $issue->assigned_to]);
            $users = $r->users(...$ids);
            if ($issue->status === IssueStatus::Resolved) {
                $users = $users->merge($r->withPermission(Permissions::ISSUES_MANAGE, $order->branch_id, $order->page_id));
            }

            return $issue->type->isBulkRelated() && $issue->status === IssueStatus::CorrectionRequired ? $users->merge($this->bulkDesigner($r, $order)) : $users;
        }, $this->make('issues.status_changed', "Issue {$issue->status->label()}", "Issue #{$issue->id} on job {$order->order_no} is now {$issue->status->label()}.", $issue, $order), $actor);
    }

    /** @return Collection<int, User> */
    private function bulkDesigner(Recipients $r, Order $order): Collection
    {
        $designerId = BulkJob::query()->where('order_id', $order->id)->value('bulk_designer_id');

        return $designerId === null ? new Collection : $r->users((int) $designerId);
    }

    private function excerpt(ProductionIssue $issue): string
    {
        return mb_strimwidth((string) $issue->getAttribute('description'), 0, 120, '…');
    }

    private function make(string $event, string $title, string $message, ProductionIssue $issue, Order $order): WorkflowNotification
    {
        return new WorkflowNotification($event, $title, $message, "/issues/view/?id={$issue->id}", ['order_id' => $order->id, 'order_no' => $order->order_no, 'production_issue_id' => $issue->id]);
    }
}
