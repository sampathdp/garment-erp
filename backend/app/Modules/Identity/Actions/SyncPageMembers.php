<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Page;
use App\Support\Database\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sets the users associated with a page and which of them are Page Owners.
 * Every user whose membership changes must be one the administrator may administer
 * (visible, no higher privileges, not themself).
 */
final class SyncPageMembers
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<int, bool> $members user id => is owner */
    public function handle(User $actor, Page $page, array $members): void
    {
        DB::transaction(function () use ($actor, $page, $members): void {
            $before = $page->users()->lockForUpdate()->get(['users.id'])
                ->mapWithKeys(fn (User $u) => [(int) $u->id => Pivot::flag($u, 'is_owner')])->all();

            $changed = [];
            foreach ($members + $before as $userId => $_) {
                if (($members[$userId] ?? null) !== ($before[$userId] ?? null)) {
                    $changed[] = $userId;
                }
            }
            if ($changed === []) {
                return;
            }

            // Eager load what the privilege check reads (AccessGuard compares permission sets).
            $users = User::query()->whereKey($changed)->with(['roles.permissions', 'permissions'])->get()->keyBy('id');
            foreach ($changed as $userId) {
                $user = $users->get($userId);
                if (! $user instanceof User || Gate::forUser($actor)->denies('administer', $user)) {
                    throw ValidationException::withMessages(['members' => ["You cannot change the page membership of user #{$userId}."]]);
                }
                if (($members[$userId] ?? false) && ! $user->is_active) {
                    throw ValidationException::withMessages(['members' => ["{$user->name} is inactive and cannot be a page owner."]]);
                }
            }

            $now = now();
            $pivots = [];
            foreach ($members as $userId => $owner) {
                $pivots[$userId] = array_key_exists($userId, $before) ? ['is_owner' => $owner] : ['is_owner' => $owner, 'created_at' => $now];
            }
            $page->users()->sync($pivots);

            ksort($before);
            ksort($members);
            $this->audit->record('pages.members_changed', $page, ['before' => $before, 'after' => $members], actor: $actor);
        });
    }
}
