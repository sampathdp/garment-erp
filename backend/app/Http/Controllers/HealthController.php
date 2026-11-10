<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public liveness/readiness probe. Reveals no versions, hostnames or error details.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => $this->check(fn () => DB::select('select 1')), 'cache' => $this->check(function (): void {
            Cache::put('health:ping', 1, 10);
            if ((int) Cache::get('health:ping') !== 1) {
                throw new \RuntimeException('cache read-back failed');
            }
        })];

        $ok = ! in_array(false, $checks, true);

        return new JsonResponse([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => array_map(static fn (bool $c): string => $c ? 'ok' : 'fail', $checks),
        ], $ok ? 200 : 503);
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
