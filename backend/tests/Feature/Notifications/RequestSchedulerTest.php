<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\Jobs\DeliverOutboxNotifications;
use App\Modules\Notifications\Models\NotificationOutbox;
use App\Modules\Notifications\WorkflowNotification;
use App\Support\Scheduling\RequestScheduler;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->alice = $this->userWithRole(Roles::SECOND_ADMIN);
});

/** A notification whose fast-path job never ran (no cron, no worker). */
function pendingOutboxRow(int $userId): NotificationOutbox
{
    $notification = new WorkflowNotification('payment.pending', 'Payment waiting for verification', 'A payment needs verification.', '/payments/view/?id=1', []);
    $row = new NotificationOutbox([
        'notification_id' => (string) Str::uuid(), 'user_id' => $userId, 'event' => $notification->event,
        'payload' => $notification->payload(), 'status' => NotificationOutbox::PENDING, 'available_at' => now(),
    ]);
    $row->save();

    return $row;
}

it('delivers pending outbox notifications without cron and writes the heartbeat', function () {
    $row = pendingOutboxRow($this->alice->id);

    app(RequestScheduler::class)->tick();

    expect($row->fresh()->status)->toBe(NotificationOutbox::SENT)
        ->and($this->alice->notifications()->count())->toBe(1)
        ->and(Cache::get(HealthController::HEARTBEAT_KEY))->not->toBeNull();
});

it('never puts a time limit on a console process (the test suite died 60 s after a tick)', function () {
    $before = ini_get('max_execution_time');

    app(RequestScheduler::class)->tick();

    expect(ini_get('max_execution_time'))->toBe($before);
});

it('drains the database queue in-process (no worker, no cron)', function () {
    $row = pendingOutboxRow($this->alice->id);
    Queue::connection('database')->push(new DeliverOutboxNotifications([$row->id]));
    expect(DB::table('jobs')->count())->toBe(1);

    app(RequestScheduler::class)->tick();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($row->fresh()->status)->toBe(NotificationOutbox::SENT);
});

it('runs at most once per interval across requests', function () {
    app(RequestScheduler::class)->tick();
    $row = pendingOutboxRow($this->alice->id);

    app(RequestScheduler::class)->tick(); // same interval: skipped
    expect($row->fresh()->status)->toBe(NotificationOutbox::PENDING);

    $this->travel(RequestScheduler::INTERVAL_SECONDS + 1)->seconds();
    app(RequestScheduler::class)->tick();
    expect($row->fresh()->status)->toBe(NotificationOutbox::SENT);
});

it('reports an old heartbeat as idle, not degraded, in request-driven mode', function () {
    config(['app.schedule_on_requests' => true]);
    app(RequestScheduler::class)->tick();
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.scheduler', 'ok');

    $this->travel(HealthController::HEARTBEAT_STALE_SECONDS + 60)->seconds();
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.scheduler', 'idle');
});
