<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app workflow notification (database channel, polled by the frontend).
 *
 * Never sent directly: Notify writes it to the transactional outbox inside the business
 * transaction and OutboxDispatcher delivers it with the outbox row's id (idempotent). The
 * payload is display data only: no personal data beyond what the recipient may already see on
 * the linked record. `link` is an in-app path (the job deep link).
 */
final class WorkflowNotification extends Notification
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $link = null,
        public readonly array $context = [],
    ) {}

    /**
     * Rebuilds the notification from an outbox payload, keeping the outbox's notification id.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromOutbox(string $id, array $payload): self
    {
        $context = array_filter($payload, fn ($v, $k) => ! in_array($k, ['event', 'title', 'message', 'link'], true) && (is_scalar($v) || $v === null), ARRAY_FILTER_USE_BOTH);
        $notification = new self((string) ($payload['event'] ?? 'notification'), (string) ($payload['title'] ?? ''), (string) ($payload['message'] ?? ''),
            isset($payload['link']) ? (string) $payload['link'] : null, $context);
        $notification->id = $id;

        return $notification;
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
    public function payload(): array
    {
        return ['event' => $this->event, 'title' => $this->title, 'message' => $this->message, 'link' => $this->link, ...$this->context];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }
}
