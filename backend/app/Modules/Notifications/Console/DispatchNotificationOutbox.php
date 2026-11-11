<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Support\OutboxDispatcher;
use Illuminate\Console\Command;

/** Scheduled sweep of the notification outbox (every minute; time-boxed for shared hosting). */
final class DispatchNotificationOutbox extends Command
{
    protected $signature = 'notifications:dispatch-outbox {--limit=500}';

    protected $description = 'Deliver pending in-app notifications from the transactional outbox';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $result = $dispatcher->deliver(null, (int) $this->option('limit'));
        $this->line("Sent {$result['sent']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
