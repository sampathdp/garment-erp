<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app workflow notification (database channel, polled by the frontend).
 * Queued and only dispatched after the surrounding transaction commits, so a rolled-back
 * change never notifies anyone. Payload is display data only: no personal data beyond
 * what the recipient may already see on the linked record.
 */
final class WorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $link = null,
        public readonly array $context = [],
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'title' => $this->title, 'message' => $this->message, 'link' => $this->link, ...$this->context];
    }
}
