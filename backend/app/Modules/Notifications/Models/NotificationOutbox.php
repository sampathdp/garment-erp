<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transactional outbox row: one notification for one recipient, written inside the business
 * transaction (so it exists exactly when the change is committed) and delivered later by
 * OutboxDispatcher. `notification_id` becomes the in-app notification's id, which makes
 * delivery idempotent.
 *
 * @property string $notification_id
 * @property int $user_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status pending | sent | failed
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $sent_at
 */
class NotificationOutbox extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $table = 'notification_outbox';

    /** @var list<string> */
    protected $fillable = ['notification_id', 'user_id', 'event', 'payload', 'status', 'available_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'user_id' => 'integer',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
