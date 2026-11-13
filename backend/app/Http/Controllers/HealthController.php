<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public liveness/readiness probe. Reveals no versions, hostnames, counts or error details.
 *
 * `scheduler` reports the heartbeat written every minute by the cron-driven scheduler
 * (routes/console.php). On shared hosting the queue and the notification outbox are drained
 * ONLY by that cron entry, so a stopped cron must show up in uptime monitoring:
 *   ok       heartbeat within the last 5 minutes
 *   stale    heartbeat older than that → 503 (cron stopped or failing)
 *   unknown  never ran yet (fresh install before the cron entry exists) → does not fail the probe
 */
final class HealthController
{
    public const HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    public const HEARTBEAT_STALE_SECONDS = 300;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')) ? 'ok' : 'fail',
            'cache' => $this->check(function (): void {
                Cache::put('health:ping', 1, 10);
                if ((int) Cache::get('health:ping') !== 1) {
                    throw new \RuntimeException('cache read-back failed');
                }
            }) ? 'ok' : 'fail',
            'scheduler' => $this->scheduler(),
        ];

        $ok = ! in_array('fail', $checks, true) && $checks['scheduler'] !== 'stale';

        return new JsonResponse(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }

    private function scheduler(): string
    {
        try {
            $beat = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return 'unknown'; // the cache check above already reports the failure
        }

        if (! is_int($beat) && ! is_numeric($beat)) {
            return 'unknown';
        }

        return now()->getTimestamp() - (int) $beat <= self::HEARTBEAT_STALE_SECONDS ? 'ok' : 'stale';
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            Log::error('Health check failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }
    }
}
