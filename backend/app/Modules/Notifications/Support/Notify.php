<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Jobs\DeliverOutboxNotifications;
use App\Modules\Notifications\Models\NotificationOutbox;
use App\Modules\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records an in-app notification through the transactional outbox (Phase 08).
 *
 * Recipients are resolved now, de-duplicated (the acting user is never notified) and one
 * outbox row per recipient is written inside the CURRENT transaction: a rolled-back change
 * notifies no one, and a committed change can never lose its notifications even when the
 * queue job fails — the scheduled sweep delivers whatever is still pending.
 */
final class Notify
{
    public function __construct(public readonly Recipients $recipients) {}

    /** @param  callable(Recipients): Collection<int, User>  $resolve */
    public function send(callable $resolve, WorkflowNotification $notification, ?User $actor = null): void
    {
        $users = $resolve($this->recipients)->filter()->unique('id')
            ->reject(fn (User $u): bool => ! $u->is_active || ($actor !== null && $u->id === $actor->id))
            ->values();
        if ($users->isEmpty()) {
            return;
        }

        $now = now();
        $ids = [];
        foreach ($users as $user) {
            $row = new NotificationOutbox([
                'notification_id' => (string) Str::uuid(), 'user_id' => $user->id, 'event' => $notification->event,
                'payload' => $notification->payload(), 'status' => NotificationOutbox::PENDING, 'available_at' => $now,
            ]);
            $row->save();
            $ids[] = $row->id;
        }

        // Fast path after commit (immediately when no transaction is open).
        DB::afterCommit(fn () => DeliverOutboxNotifications::dispatch($ids));
    }
}
