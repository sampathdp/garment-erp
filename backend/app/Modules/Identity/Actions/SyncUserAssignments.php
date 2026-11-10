<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccessGuard;
use App\Support\Database\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * Sets a user's branch and page assignments (and page ownership).
 *
 * An administrator only controls assignments inside their own scope: assignments to branches
 * or pages the administrator cannot access are kept untouched, and new ones must be inside it.
 */
final class SyncUserAssignments
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessGuard $guard,
    ) {}

    /**
     * @param  list<int>  $branchIds
     * @param  list<int>  $pageIds
     * @param  list<int>  $ownerPageIds  subset of $pageIds
     */
    public function handle(User $actor, User $user, array $branchIds, array $pageIds, array $ownerPageIds = [], bool $audit = true): void
    {
        $this->guard->assertScopeAssignable($actor, $branchIds, $pageIds);

        DB::transaction(function () use ($actor, $user, $branchIds, $pageIds, $ownerPageIds, $audit): void {
            $before = $this->snapshot($user);

            $keptBranches = array_values(array_filter($before['branch_ids'], fn (int $id) => ! $actor->canAccessBranch($id)));
            $keptPages = array_filter($before['pages'], fn (bool $owner, int $id) => ! $actor->canAccessPage($id), ARRAY_FILTER_USE_BOTH);

            $now = now();
            // created_at only for new rows, so existing assignment dates are preserved.
            $branches = [];
            foreach (array_unique([...$keptBranches, ...$branchIds]) as $id) {
                $branches[$id] = in_array($id, $before['branch_ids'], true) ? [] : ['created_at' => $now];
            }
            $user->branches()->sync($branches);

            $pages = $keptPages;
            foreach ($pageIds as $id) {
                $pages[$id] = in_array($id, $ownerPageIds, true);
            }
            $pagePivots = [];
            foreach ($pages as $id => $owner) {
                $pagePivots[$id] = array_key_exists($id, $before['pages']) ? ['is_owner' => $owner] : ['is_owner' => $owner, 'created_at' => $now];
            }
            $user->pages()->sync($pagePivots);

            $user->refreshAccessScope();
            $after = $this->snapshot($user);

            if ($audit && $after !== $before) {
                $this->audit->record('users.assignments_changed', $user, ['before' => $before, 'after' => $after], actor: $actor);
            }
        });
    }

    /** @return array{branch_ids: list<int>, pages: array<int, bool>} */
    private function snapshot(User $user): array
    {
        $branchIds = $user->branches()->orderBy('branches.id')->pluck('branches.id')->map(fn ($id) => (int) $id)->values()->all();
        $pages = $user->pages()->orderBy('pages.id')->get(['pages.id'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => Pivot::flag($p, 'is_owner')])->all();

        return ['branch_ids' => $branchIds, 'pages' => $pages];
    }
}
