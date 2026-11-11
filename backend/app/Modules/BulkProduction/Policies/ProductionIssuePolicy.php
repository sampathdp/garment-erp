<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Policies;

use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Production issues (Phase 07 central register). Reporting is authorized on the record the
 * issue is raised from (CuttingJobPolicy::reportIssue, OrderPolicy::reportIssue).
 *
 * Who may SEE an issue (within branch/page scope): production.view or production.issues.manage
 * holders, the reporter, the assignee, the cutter of the cutting job it was raised on, and —
 * for bulk-related issues — the order's Bulk Designer. Anyone else gets a 404.
 *
 * Managers (production.issues.manage) assign, investigate, resolve, close and reopen; the
 * assignee may investigate, request a correction and resolve.
 */
final class ProductionIssuePolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRODUCTION_VIEW) || $user->can(Permissions::ISSUES_MANAGE) || $user->can(Permissions::ISSUES_REPORT);
    }

    public function view(User $user, ProductionIssue $issue): Response
    {
        return $this->decide($this->visible($user, $issue), true);
    }

    /** Assign the issue and set the blocking flag. */
    public function manage(User $user, ProductionIssue $issue): Response
    {
        return $this->decide($this->visible($user, $issue), $user->can(Permissions::ISSUES_MANAGE), $issue->status !== IssueStatus::Closed, 'This issue is closed.');
    }

    /** Change the status (the Action allows close / reopen only for managers). */
    public function transition(User $user, ProductionIssue $issue): Response
    {
        $permitted = $user->can(Permissions::ISSUES_MANAGE)
            || ($user->can(Permissions::ISSUES_REPORT) && $issue->assigned_to !== null && $issue->assigned_to === $user->id);

        return $this->decide($this->visible($user, $issue), $permitted, $issue->status !== IssueStatus::Closed, 'This issue is closed.');
    }

    private function visible(User $user, ProductionIssue $issue): bool
    {
        if (! $issue->isVisibleTo($user)) {
            return false;
        }
        if ($user->can(Permissions::PRODUCTION_VIEW) || $user->can(Permissions::ISSUES_MANAGE)) {
            return true;
        }
        if ($issue->raised_by === $user->id || ($issue->assigned_to !== null && $issue->assigned_to === $user->id)) {
            return true;
        }
        if ($issue->cutting_job_id !== null && CuttingJob::query()->whereKey($issue->cutting_job_id)->where('supervisor_id', $user->id)->exists()) {
            return true;
        }

        return $issue->type->isBulkRelated()
            && BulkJob::query()->where('order_id', $issue->getAttribute('order_id'))->where('bulk_designer_id', $user->id)->exists();
    }
}
