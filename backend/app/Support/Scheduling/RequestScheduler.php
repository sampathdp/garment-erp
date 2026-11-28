<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use App\Http\Controllers\HealthController;
use App\Modules\Notifications\Support\OutboxDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background work without cron (shared hosting): runs after the HTTP response has been sent
 * (app terminating callback; PHP-FPM / LiteSpeed finish the request first), at most once per
 * INTERVAL_SECONDS across all requests. The frontend polls `notifications/poll` every 20 s
 * for every signed-in user, so there is always traffic to drive it while anyone is working.
 *
 * Each tick drains the database queue (outbox fast-path jobs, password-reset mails) and sweeps
 * the notification outbox, both time-boxed; daily housekeeping runs once a day. A cron entry
 * for `schedule:run` may still exist: the queue and the outbox lock their rows, so running
 * both never duplicates work. Disable with SCHEDULE_ON_REQUESTS=false (VPS with a worker).
 */
final class RequestScheduler
{
    public const INTERVAL_SECONDS = 30;

    private const TICK_KEY = 'request-scheduler:tick';

    private const DAILY_KEY = 'request-scheduler:daily';

    public function __construct(private readonly OutboxDispatcher $outbox) {}

    public function tick(): void
    {
        try {
            // Atomic on every cache driver (database: insert-if-absent), so one request per interval wins.
            if (! Cache::add(self::TICK_KEY, now()->getTimestamp(), self::INTERVAL_SECONDS)) {
                return;
            }
            Cache::forever(HealthController::HEARTBEAT_KEY, now()->getTimestamp());
        } catch (Throwable $e) {
            Log::warning('Request scheduler could not start a tick.', ['error' => $e::class]);

            return;
        }

        @set_time_limit(60);
        ignore_user_abort(true);

        $this->safely('queue', fn () => Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default,notifications,documents,imports',
            '--stop-when-empty' => true,
            '--max-time' => 20,
            '--tries' => 3,
            '--backoff' => 30,
        ]));
        $this->safely('outbox', fn () => $this->outbox->deliver(null, 200, 15));

        if (Cache::add(self::DAILY_KEY, now()->getTimestamp(), 86400)) {
            $this->safely('prune-failed', fn () => Artisan::call('queue:prune-failed', ['--hours' => 720]));
            $this->safely('sanctum-prune', fn () => Artisan::call('sanctum:prune-expired', ['--hours' => 24]));
        }
    }

    private function safely(string $step, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('Request scheduler step failed.', ['step' => $step, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
