<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Support\Roles;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Support\Facades\DB;

/*
| Real concurrency: the same workflow request is sent by several OS processes at the same instant
| (tests/Support/race-worker.php) against the MySQL test database. Exactly one may win; every other
| process must get a clean domain refusal (409), never a duplicate, a double approval, a deadlock or
| a 500. The fixtures are committed so the workers can see them, and every table is emptied again
| afterwards. Run with: vendor/bin/pest --group=concurrency
*/

/**
 * @param  list<array<string, int|string>>  $workers  arguments per worker process
 * @return list<array<string, mixed>>
 */
function race(string $scenario, array $workers): array
{
    $db = config('database.connections.'.config('database.default'));
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_URL' => '',
        'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
        'MAIL_MAILER' => 'array', 'AUDIT_DB_TRIGGERS' => 'true',
    ]);
    // Booting Laravel takes a few seconds on this disk; every worker then fires at the same instant.
    $startAt = sprintf('%.6F', microtime(true) + 8);

    $processes = [];
    foreach ($workers as $args) {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/race-worker.php'), $scenario, $startAt, json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect($process)->not->toBeFalse();
        $processes[] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $line = collect(explode(PHP_EOL, $out))->last(fn (string $l) => str_starts_with($l, '{'));
        expect($line)->not->toBeNull("Worker printed no result. stdout: {$out} stderr: {$err}");
        $results[] = json_decode((string) $line, true);
    }

    // Never a crash, deadlock or duplicate-key error: only success or a clean refusal.
    foreach ($results as $r) {
        expect($r['ok'] || in_array($r['status'], [403, 409, 422], true))->toBeTrue("{$scenario}: ".json_encode($r));
    }

    return $results;
}

/** Commits the fixtures built inside RefreshDatabase's transaction, so other processes can see them. */
function commitFixtures(): void
{
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
}

/** Empties every table again (TRUNCATE does not fire the audit_logs triggers). */
function truncateAll(): void
{
    $database = DB::getDatabaseName();
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach (DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$database, 'BASE TABLE']) as $row) {
        if ($row->t !== 'migrations') {
            DB::statement("TRUNCATE TABLE `{$row->t}`");
        }
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}

/** @param  list<array<string, mixed>>  $results */
function winners(array $results): int
{
    return count(array_filter($results, fn (array $r) => $r['ok']));
}

it('lets exactly one of several simultaneous requests win at every workflow step', function () {
    deliveryWorld($this);
    $second2 = $this->userWithRole(Roles::SECOND_ADMIN, pages: [$this->pageA]);

    // Fixtures at every contested step (each helper drives its own order through the API).
    $verifyOrder = createOrder($this);
    $pending = recordPayment($this, $verifyOrder, 300000);

    $urgentOrder = createOrder($this, ['due_date' => '2026-11-12']);

    $listOrder = approvedOrder($this);
    submitJobDetails($this, $listOrder);
    fillList($this, $listOrder);
    $list = garmentList($this, $listOrder);

    $bulkReady = readyBulkJob($this);
    $bulkOrderId = $bulkReady->order_id;
    // Simulate the job not created yet while all prerequisites hold (final design + payment verified at once).
    DB::table('audit_logs')->count(); // keep audit rows (subject is a morph, no FK)
    DB::table('bulk_jobs')->where('id', $bulkReady->id)->delete();

    $sampleJob = printReadyJob($this);
    sendForPrinting($this, $sampleJob)->assertOk();
    uploadSample($this, $sampleJob)->assertOk();

    $cutting = readyToCompleteCutting($this);
    $delivered = deliveredAndSettled($this);
    uploadDeliveryDocument($this, $delivered)->assertSuccessful();

    $idemOrder = createOrder($this);

    $versions = ['sample' => printVersion($sampleJob), 'cutting' => cutVersion($cutting), 'delivery' => deliveryVersion($delivered)];

    commitFixtures();

    try {
        // Payment verification: two verifiers click at once → verified once.
        $r = race('verify-payment', [['user' => $this->second->id, 'payment' => $pending->id], ['user' => $second2->id, 'payment' => $pending->id]]);
        expect(winners($r))->toBe(1)
            ->and(Payment::query()->findOrFail($pending->id)->status->value)->toBe('verified')
            ->and(DB::table('payment_verification_events')->where('payment_id', $pending->id)->where('action', 'verified')->count())->toBe(1)
            ->and(Order::query()->findOrFail($verifyOrder->id)->verified_paid_minor)->toBe(300000)
            ->and(OrderStatusHistory::query()->where('order_id', $verifyOrder->id)->where('to_status', 'confirmed')->count())->toBe(1);

        // Urgent approval: two Second Admins take the same slot while the Operation Admin takes the other.
        $r = race('urgent-slot', [
            ['user' => $this->second->id, 'order' => $urgentOrder->id, 'slot' => 'second_admin'],
            ['user' => $second2->id, 'order' => $urgentOrder->id, 'slot' => 'second_admin'],
            ['user' => $this->ops->id, 'order' => $urgentOrder->id, 'slot' => 'operation_admin'],
        ]);
        expect(winners($r))->toBe(2)
            ->and(UrgentApprovalDecision::query()->whereHas('urgentApproval', fn ($q) => $q->where('order_id', $urgentOrder->id))->count())->toBe(2)
            ->and(Order::query()->findOrFail($urgentOrder->id)->urgent_status)->toBe(UrgentStatus::Approved);

        // List confirmation (issues the invoice): three confirmations of the same version → one invoice.
        $r = race('confirm-list', array_fill(0, 3, ['user' => $this->owner->id, 'list' => $list['id'], 'version' => $list['version']]));
        expect(winners($r))->toBe(1)
            ->and(Invoice::query()->where('order_id', $listOrder->id)->count())->toBe(1)
            ->and(Invoice::query()->pluck('invoice_no')->duplicates()->all())->toBe([]);

        // Bulk job creation triggered from several places at once → one job per order.
        $r = race('create-bulk', array_fill(0, 4, ['order' => $bulkOrderId]));
        expect(count(array_filter($r, fn ($x) => $x['ok'] && $x['id'] !== null)))->toBe(1)
            ->and(BulkJob::query()->where('order_id', $bulkOrderId)->count())->toBe(1);

        // Sample approval: double click / two owners → approved once, order moved once.
        $r = race('approve-sample', array_fill(0, 3, ['user' => $this->owner->id, 'job' => $sampleJob->id, 'version' => $versions['sample']]));
        expect(winners($r))->toBe(1)
            ->and(PrintSample::query()->where('print_job_id', $sampleJob->id)->where('decision', 'approved')->count())->toBe(1)
            ->and(OrderStatusHistory::query()->where('order_id', $sampleJob->order_id)->where('to_status', 'printing')->count())->toBe(1);

        // Production completion: cutting completed once, one sewing job.
        $r = race('complete-cutting', array_fill(0, 3, ['user' => $this->ops->id, 'job' => $cutting->id, 'version' => $versions['cutting']]));
        expect(winners($r))->toBe(1)
            ->and(SewingJob::query()->where('cutting_job_id', $cutting->id)->count())->toBe(1)
            ->and(OrderStatusHistory::query()->where('order_id', $cutting->order_id)->where('to_status', 'sewing')->count())->toBe(1);

        // Final job completion.
        $r = race('complete-job', array_fill(0, 3, ['user' => $this->driver->id, 'delivery' => $delivered->id, 'version' => $versions['delivery']]));
        expect(winners($r))->toBe(1)
            ->and(Order::query()->findOrFail($delivered->order_id)->status)->toBe(OrderStatus::Completed)
            ->and(OrderStatusHistory::query()->where('order_id', $delivered->order_id)->where('to_status', 'completed')->count())->toBe(1);

        // Payment creation with the same Idempotency-Key from four tabs → one payment, same id everywhere.
        $r = race('record-payment', array_fill(0, 4, ['user' => $this->owner->id, 'order' => $idemOrder->id, 'amount' => 1000, 'key' => '0b9e7f52-3a41-4c8d-8e2f-7d6c5b4a3e21']));
        expect(winners($r))->toBe(4)
            ->and(collect($r)->pluck('id')->unique()->count())->toBe(1)
            ->and(Payment::query()->where('order_id', $idemOrder->id)->count())->toBe(1);
    } finally {
        truncateAll();
    }
})->group('concurrency');
