<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationOutbox;
use App\Modules\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Delivers pending outbox rows as in-app (database) notifications.
 *
 *  - Each row is delivered in its own short transaction under a row lock; the notification
 *    row is inserted with the outbox `notification_id`, so a retry can never duplicate it.
 *  - A failure records the error, increments `attempts` and schedules the next try with
 *    exponential backoff; after MAX_ATTEMPTS the row is marked failed (dead letter, logged).
 *  - Called right after commit (DeliverOutboxNotifications job) and every minute by the
 *    scheduler (`notifications:dispatch-outbox`), which catches anything a lost job missed.
 */
final class OutboxDispatcher
{
    public const MAX_ATTEMPTS = 8;

    /**
     * @param  list<int>|null  $ids  restrict to these rows (fast path), or null for every due row
     * @return array{sent: int, failed: int}
     */
    public function deliver(?array $ids = null, int $limit = 200, int $budgetSeconds = 40): array
    {
        $started = microtime(true);
        $result = ['sent' => 0, 'failed' => 0];

        $due = NotificationOutbox::query()->where('status', NotificationOutbox::PENDING)->where('available_at', '<=', now())
            ->when($ids !== null, fn ($q) => $q->whereKey($ids))
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            if (microtime(true) - $started > $budgetSeconds) {
                break; // shared hosting: keep every run well under the cron window
            }
            $this->deliverOne((int) $id) ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    private function deliverOne(int $id): bool
    {
        try {
            DB::transaction(function () use ($id): void {
                $row = NotificationOutbox::query()->lockForUpdate()->find($id);
                if ($row === null || $row->status !== NotificationOutbox::PENDING) {
                    return; // already delivered by a concurrent run
                }
                $user = User::query()->find($row->user_id);
                if ($user !== null && $user->is_active) {
                    Notification::sendNow($user, WorkflowNotification::fromOutbox($row->notification_id, $row->payload), ['database']);
                }
                $row->forceFill(['status' => NotificationOutbox::SENT, 'sent_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null])->save();
            });

            return true;
        } catch (Throwable $e) {
            $row = NotificationOutbox::query()->find($id);
            if ($row !== null) {
                $attempts = $row->attempts + 1;
                $dead = $attempts >= self::MAX_ATTEMPTS;
                $row->forceFill([
                    'attempts' => $attempts,
                    'status' => $dead ? NotificationOutbox::FAILED : NotificationOutbox::PENDING,
                    'available_at' => now()->addSeconds(min(3600, 30 * (2 ** ($attempts - 1)))),
                    'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 500),
                ])->save();
                if ($dead) {
                    Log::error('Notification outbox row gave up after retries.', ['outbox_id' => $id, 'event' => $row->event, 'error' => $e::class]);
                }
            }

            return false;
        }
    }
}
