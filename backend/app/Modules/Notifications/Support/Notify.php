<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a queued in-app notification after the current transaction commits. Recipients are
 * resolved lazily (after commit), de-duplicated, and the acting user is never notified.
 */
final class Notify
{
    public function __construct(public readonly Recipients $recipients) {}

    /** @param  callable(Recipients): Collection<int, User>  $resolve */
    public function send(callable $resolve, WorkflowNotification $notification, ?User $actor = null): void
    {
        DB::afterCommit(function () use ($resolve, $notification, $actor): void {
            $users = $resolve($this->recipients)->filter()->unique('id')
                ->reject(fn (User $u): bool => $actor !== null && $u->id === $actor->id)
                ->values();
            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }
        });
    }
}
