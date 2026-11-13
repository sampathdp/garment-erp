<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Shared hosting (profile A): one cron entry runs the scheduler every minute:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
| The scheduler then drains the database queue for up to ~50 s, so no long-running worker is needed.
| On a VPS (profile B) remove the queue:work entry and run Horizon under Supervisor instead.
*/

Schedule::command('queue:work database --queue=default,notifications,documents,imports --stop-when-empty --max-time=50 --tries=3 --backoff=30')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Transactional outbox sweep: delivers notifications whose fast-path job failed or was lost.
Schedule::command('notifications:dispatch-outbox')->everyMinute()->withoutOverlapping(5)->runInBackground();

// Heartbeat for /api/v1/health ("scheduler"): a stopped cron entry becomes visible to uptime monitoring.
Schedule::call(static fn () => Cache::forever(HealthController::HEARTBEAT_KEY, now()->getTimestamp()))
    ->everyMinute()->name('health-heartbeat');

// Reporting summaries are cached (ReportCache); nothing to precompute on a schedule yet.
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
