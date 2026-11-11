<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\Jobs\DeliverOutboxNotifications;
use App\Modules\Notifications\Models\NotificationOutbox;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\OutboxDispatcher;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use Database\Seeders\PermissionSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->alice = $this->userWithRole(Roles::OPERATION_ADMIN);
    $this->bob = $this->userWithRole(Roles::OPERATION_ADMIN);
});

function notifyBoth($test, ?User $actor = null): void
{
    app(Notify::class)->send(fn (Recipients $r) => $r->users($test->alice->id, $test->bob->id),
        new WorkflowNotification('test.event', 'Hello', 'A job needs you', '/cutting/view/?id=7', ['order_no' => 'CMB-JRSY-26-00001']), $actor);
}

describe('transactional outbox', function () {
    it('writes one row per recipient inside the transaction and delivers after commit', function () {
        DB::transaction(fn () => notifyBoth($this, $this->bob)); // the actor is never notified

        expect(NotificationOutbox::query()->count())->toBe(1)
            ->and(NotificationOutbox::query()->sole()->status)->toBe('sent')
            ->and($this->alice->notifications()->count())->toBe(1)
            ->and($this->bob->notifications()->count())->toBe(0);
        $n = $this->alice->notifications()->sole();
        expect($n->id)->toBe(NotificationOutbox::query()->value('notification_id'))
            ->and($n->data['link'])->toBe('/cutting/view/?id=7')->and($n->data['order_no'])->toBe('CMB-JRSY-26-00001');
    });

    it('notifies no one when the business transaction rolls back', function () {
        try {
            DB::transaction(function () {
                notifyBoth($this);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        expect(NotificationOutbox::query()->count())->toBe(0)->and(DB::table('notifications')->count())->toBe(0);
    });

    it('keeps the notification when the queue job is lost, and the sweep delivers it', function () {
        Queue::fake(); // the fast-path job never runs
        DB::transaction(fn () => notifyBoth($this));
        Queue::assertPushed(DeliverOutboxNotifications::class);
        expect(NotificationOutbox::query()->where('status', 'pending')->count())->toBe(2)->and(DB::table('notifications')->count())->toBe(0);

        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();
        expect(NotificationOutbox::query()->where('status', 'sent')->count())->toBe(2)->and(DB::table('notifications')->count())->toBe(2);

        // Re-running (or a late duplicate job) never duplicates a notification.
        (new DeliverOutboxNotifications(NotificationOutbox::query()->pluck('id')->all()))->handle(app(OutboxDispatcher::class));
        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();
        expect(DB::table('notifications')->count())->toBe(2);
    });

    it('retries a failed delivery with backoff and delivers exactly once', function () {
        Queue::fake();
        DB::transaction(fn () => notifyBoth($this));
        $fail = true;
        Event::listen(NotificationSending::class, function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('database unavailable');
            }
        });

        $result = app(OutboxDispatcher::class)->deliver();
        expect($result)->toBe(['sent' => 0, 'failed' => 2]);
        $row = NotificationOutbox::query()->orderBy('id')->first();
        expect($row->status)->toBe('pending')->and($row->attempts)->toBe(1)->and($row->last_error)->toContain('database unavailable')
            ->and($row->available_at->isFuture())->toBeTrue();

        // Not due yet: the sweep skips it.
        expect(app(OutboxDispatcher::class)->deliver())->toBe(['sent' => 0, 'failed' => 0]);

        $fail = false;
        $this->travel(5)->minutes();
        expect(app(OutboxDispatcher::class)->deliver())->toBe(['sent' => 2, 'failed' => 0]);
        expect(DB::table('notifications')->count())->toBe(2);
    });

    it('marks a row failed after the maximum attempts (dead letter)', function () {
        Queue::fake();
        DB::transaction(fn () => notifyBoth($this));
        NotificationOutbox::query()->update(['attempts' => OutboxDispatcher::MAX_ATTEMPTS - 1]);
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('still failing'));

        app(OutboxDispatcher::class)->deliver();
        expect(NotificationOutbox::query()->where('status', 'failed')->count())->toBe(2);
    });
});

describe('notification inbox', function () {
    it('lists history, filters unread, marks one read or unread and marks all read', function () {
        DB::transaction(fn () => notifyBoth($this));
        DB::transaction(fn () => notifyBoth($this));
        $ids = $this->alice->notifications()->pluck('id');

        $this->signedInAs($this->alice)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.data.link', '/cutting/view/?id=7');
        $this->signedInAs($this->alice)->postJson("/api/v1/notifications/{$ids[0]}/read")->assertOk()->assertJsonPath('data.id', $ids[0]);
        $this->signedInAs($this->alice)->getJson('/api/v1/notifications?filter[unread]=1')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($this->alice)->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 1);
        $this->signedInAs($this->alice)->postJson("/api/v1/notifications/{$ids[0]}/unread")->assertOk()->assertJsonPath('data.read_at', null);
        $this->signedInAs($this->alice)->postJson('/api/v1/notifications/mark-all-read')->assertOk()->assertJsonPath('data.marked', 2);

        // Another user's notification is invisible.
        $this->signedInAs($this->bob)->postJson("/api/v1/notifications/{$ids[0]}/read")->assertNotFound();
        $this->asGuest()->getJson('/api/v1/notifications')->assertUnauthorized();
    });

    it('polls for new notifications since the last server time (near real time)', function () {
        $first = $this->signedInAs($this->alice)->getJson('/api/v1/notifications/poll')->assertOk()->json('data');
        expect($first['unread'])->toBe(0)->and($first['new'])->toBe([]);

        $this->travel(2)->seconds();
        DB::transaction(fn () => notifyBoth($this));
        $poll = $this->signedInAs($this->alice)->getJson('/api/v1/notifications/poll?since='.urlencode($first['server_time']))->assertOk()->json('data');
        expect($poll['unread'])->toBe(1)->and($poll['new'])->toHaveCount(1)->and($poll['new'][0]['data']['title'])->toBe('Hello');

        $this->travel(2)->seconds();
        $again = $this->signedInAs($this->alice)->getJson('/api/v1/notifications/poll?since='.urlencode($poll['server_time']))->json('data');
        expect($again['new'])->toBe([]);
    });

    it('resolves recipients by permission and scope', function () {
        $page = Page::factory()->create();
        $branch = Branch::factory()->create();
        $scoped = $this->userWith([Permissions::CUTTING_MANAGE], [$branch], [$page]);
        $outside = $this->userWith([Permissions::CUTTING_MANAGE], [Branch::factory()->create()], [$page]);
        $noPermission = $this->userWith([Permissions::CUTTING_VIEW], [$branch], [$page]);

        $ids = app(Recipients::class)->withPermission(Permissions::CUTTING_MANAGE, $branch->id, $page->id)->pluck('id')->all();
        expect($ids)->toContain($scoped->id)->not->toContain($outside->id)->not->toContain($noPermission->id);
    });
});
