<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Support\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fast path of the notification outbox: dispatched after the business transaction commits.
 * If this job is lost or fails, the rows stay pending and the scheduled sweep
 * (`notifications:dispatch-outbox`) delivers them; nothing is lost.
 */
final class DeliverOutboxNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** @param  list<int>  $ids */
    public function __construct(public readonly array $ids)
    {
        $this->onQueue('notifications');
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->ids);
    }
}
