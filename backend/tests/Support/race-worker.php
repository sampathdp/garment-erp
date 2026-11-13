<?php

declare(strict_types=1);

/*
 * Child process for the workflow concurrency tests (tests/Feature/Concurrency/WorkflowRaceTest.php).
 *
 *   php race-worker.php <scenario> <start-at unix microtime> <json arguments>
 *
 * Boots the application against the MySQL test database given in the DB_* environment, waits for
 * the shared start time (so all workers hit the database together), runs ONE Action exactly as the
 * controller would, and prints one JSON line: {"ok":true,"id":…} or {"ok":false,"status":409,"code":…}.
 * Anything that is not a clean domain refusal (deadlock, duplicate key, 500) is reported as
 * {"ok":false,"status":500,"error":…} so the test fails on it.
 */

use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\Cutting\Actions\CompleteCuttingJob;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Actions\CompleteJob;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Garments\Actions\ConfirmGarmentList;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\DecideUrgentApproval;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Actions\DecidePayment;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Actions\ReviewPrintSample;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$scenario, $startAt, $json] = [$argv[1] ?? '', (float) ($argv[2] ?? 0), $argv[3] ?? '{}'];
/** @var array<string, int|string> $a */
$a = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$user = isset($a['user']) ? User::query()->findOrFail($a['user']) : null;
if ($user !== null) {
    Auth::guard('web')->setUser($user); // the audit logger reads the acting user from the guard
}

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

try {
    $id = match ($scenario) {
        'verify-payment' => $app->make(DecidePayment::class)->handle($user, Payment::query()->findOrFail($a['payment']), true, 'Seen in bank statement')->id,
        'urgent-slot' => $app->make(DecideUrgentApproval::class)->handle($user, Order::query()->findOrFail($a['order']), UrgentApprovalSlot::from((string) $a['slot']), UrgentDecision::Approved, null)->id,
        'confirm-list' => $app->make(ConfirmGarmentList::class)->confirm($user, GarmentList::query()->findOrFail($a['list']), (int) $a['version'], true)->id,
        'create-bulk' => $app->make(CreateBulkJobIfEligible::class)->handle(Order::query()->findOrFail($a['order']), $user)?->id,
        'approve-sample' => $app->make(ReviewPrintSample::class)->approve($user, PrintJob::query()->findOrFail($a['job']), (int) $a['version'], null, 'Colours are right')->id,
        'complete-cutting' => $app->make(CompleteCuttingJob::class)->handle($user, CuttingJob::query()->findOrFail($a['job']), (int) $a['version'], 'All cut')->id,
        'complete-job' => $app->make(CompleteJob::class)->handle($user, Delivery::query()->findOrFail($a['delivery']), (int) $a['version'], 'All good')->id,
        'record-payment' => $app->make(RecordPayment::class)->handle($user, Order::query()->findOrFail($a['order']), [
            'amount_minor' => (int) $a['amount'], 'method' => PaymentMethod::Cash, 'reference_no' => null, 'payment_date' => '2026-11-09', 'notes' => null,
        ], null, (string) $a['key'])->id,
        default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]"),
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'id' => $id]).PHP_EOL);
} catch (ApiException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => $e->status, 'code' => $e->errorCode]).PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 500, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)]).PHP_EOL);
}
