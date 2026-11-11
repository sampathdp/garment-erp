<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\BulkProduction\Models\ProductionIssueEvent;
use App\Modules\BulkProduction\Support\ProductionIssueNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Issue management. Lock order: the ORDER row, then the issue (cutting completion reads the
 * issues under the order lock, so a resolution and a completion never interleave).
 *
 *  - update():     issue managers assign the issue (active user in scope who can report issues)
 *                  and decide whether it blocks completion.
 *  - transition(): the ONLY place an issue's status changes (IssueStatus::nextStates()).
 *                  Resolving needs a resolution; closing and reopening need production.issues.manage
 *                  (the assignee may investigate, request a correction or resolve).
 */
final class ManageProductionIssue
{
    public function __construct(private readonly ProductionIssueNotifications $notify, private readonly AuditLogger $audit) {}

    public function update(User $actor, ProductionIssue $issue, int $expectedVersion, ?int $assigneeId, bool $assigneeGiven, ?bool $blocking): ProductionIssue
    {
        return DB::transaction(function () use ($actor, $issue, $expectedVersion, $assigneeId, $assigneeGiven, $blocking): ProductionIssue {
            [$issue, $order] = $this->lock($issue, $expectedVersion);
            if ($issue->status === IssueStatus::Closed) {
                throw ApiException::conflict('invalid_state', 'This issue is closed.');
            }

            $before = ['assigned_to' => $issue->assigned_to, 'is_blocking' => $issue->is_blocking];
            $assignee = null;
            if ($assigneeGiven && $assigneeId !== $issue->assigned_to) {
                if ($assigneeId !== null) {
                    $assignee = User::query()->whereKey($assigneeId)->where('is_active', true)->first();
                    $ok = $assignee !== null && ($assignee->can(Permissions::ISSUES_REPORT) || $assignee->can(Permissions::ISSUES_MANAGE))
                        && $assignee->canAccessBranch($order->branch_id) && $assignee->canAccessPage($order->page_id);
                    if (! $ok) {
                        throw new ApiException(422, 'validation_failed', 'Choose an active user who can work on this issue.', ['assigned_to' => ['Choose an active user who can work on this issue.']]);
                    }
                }
                $issue->forceFill(['assigned_to' => $assigneeId, 'assigned_at' => $assigneeId === null ? null : now()]);
            }
            if ($blocking !== null) {
                $issue->forceFill(['is_blocking' => $blocking]);
            }
            if (! $issue->isDirty()) {
                return $issue;
            }

            $this->save($issue);
            if ($issue->wasChanged('assigned_to')) {
                ProductionIssueEvent::query()->create(['production_issue_id' => $issue->id, 'from_status' => $issue->status, 'to_status' => $issue->status,
                    'note' => $assignee === null ? 'Unassigned.' : "Assigned to {$assignee->name}.", 'actor_id' => $actor->id]);
            }
            $this->audit->record('issues.updated', $issue, ['before' => $before, 'after' => ['assigned_to' => $issue->assigned_to, 'is_blocking' => $issue->is_blocking]], actor: $actor, branchId: $order->branch_id);
            if ($assignee !== null) {
                $this->notify->assigned($issue, $order, $assignee, $actor);
            }

            return $issue;
        });
    }

    public function transition(User $actor, ProductionIssue $issue, int $expectedVersion, IssueStatus $to, ?string $note, ?string $resolution): ProductionIssue
    {
        return DB::transaction(function () use ($actor, $issue, $expectedVersion, $to, $note, $resolution): ProductionIssue {
            [$issue, $order] = $this->lock($issue, $expectedVersion);
            $from = $issue->status;
            if (! $from->canTransitionTo($to)) {
                throw ApiException::conflict('invalid_transition', "The issue cannot move from {$from->label()} to {$to->label()}.");
            }
            $managerOnly = $to === IssueStatus::Closed || ($from === IssueStatus::Resolved && $to === IssueStatus::Open);
            if ($managerOnly && ! $actor->can(Permissions::ISSUES_MANAGE)) {
                throw new ApiException(403, 'forbidden', 'Only issue managers can close or reopen an issue.');
            }
            if ($to === IssueStatus::Resolved && ($resolution === null || mb_strlen($resolution) < 3)) {
                throw new ApiException(422, 'validation_failed', 'Describe the resolution.', ['resolution' => ['Describe the resolution.']]);
            }
            if ($to === IssueStatus::Open && ($note === null || mb_strlen($note) < 3)) {
                throw new ApiException(422, 'validation_failed', 'A reason is required to reopen an issue.', ['note' => ['A reason is required to reopen an issue.']]);
            }

            $issue->forceFill(['status' => $to]);
            match ($to) {
                IssueStatus::Resolved => $issue->forceFill(['resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now()]),
                IssueStatus::Closed => $issue->forceFill(['closed_by' => $actor->id, 'closed_at' => now()]),
                IssueStatus::Open => $issue->forceFill(['resolution' => null, 'resolved_by' => null, 'resolved_at' => null]),
                default => null,
            };
            $this->save($issue);
            ProductionIssueEvent::query()->create(['production_issue_id' => $issue->id, 'from_status' => $from, 'to_status' => $to,
                'note' => mb_substr((string) ($to === IssueStatus::Resolved ? $resolution : $note), 0, 2000) ?: null, 'actor_id' => $actor->id]);
            $this->audit->record('issues.status_changed', $issue, ['before' => ['status' => $from->value], 'after' => ['status' => $to->value, 'note' => $note, 'resolution' => $resolution]], actor: $actor, branchId: $order->branch_id);
            $this->notify->statusChanged($issue, $order, $actor);

            return $issue;
        });
    }

    /** @return array{0: ProductionIssue, 1: Order} */
    private function lock(ProductionIssue $issue, int $expectedVersion): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($issue->getAttribute('order_id'));
        $issue = ProductionIssue::query()->lockForUpdate()->findOrFail($issue->id);
        if ($issue->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The issue was changed by someone else. Reload it and try again.');
        }

        return [$issue, $order];
    }

    private function save(ProductionIssue $issue): void
    {
        $issue->forceFill(['version' => $issue->version + 1])->save();
    }
}
