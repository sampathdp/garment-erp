<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Policies;

use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Production issues. Reporting is authorized on the job the issue is raised from
 * (OrderPolicy / BulkAssignmentPolicy / CuttingJobPolicy::reportIssue). Operation Admins manage them.
 */
final class ProductionIssuePolicy extends ScopedPolicy
{
    public function view(User $user, ProductionIssue $issue): Response
    {
        $visible = $issue->isVisibleTo($user)
            && ($user->can(Permissions::PRODUCTION_VIEW) || (int) $issue->getAttribute('raised_by') === $user->id);

        return $this->decide($visible, true);
    }

    public function manage(User $user, ProductionIssue $issue): Response
    {
        return $this->decide(
            $issue->isVisibleTo($user),
            $user->can(Permissions::ISSUES_MANAGE),
            $issue->status === IssueStatus::Open,
            'This issue is already closed.',
        );
    }
}
