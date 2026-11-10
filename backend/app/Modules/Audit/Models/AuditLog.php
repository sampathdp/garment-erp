<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail. Write through App\Modules\Audit\AuditLogger only.
 * Updates and deletes are refused here and (on MySQL) by database triggers.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['occurred_at', 'actor_id', 'action', 'subject_type', 'subject_id', 'branch_id', 'ip', 'user_agent', 'request_id', 'changes'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'changes' => 'array',
            'subject_id' => 'integer',
            'branch_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('audit_logs is append-only.'));
        static::deleting(static fn () => throw new LogicException('audit_logs is append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function ipAddress(): ?string
    {
        $raw = $this->getAttribute('ip');

        return is_string($raw) && $raw !== '' ? (inet_ntop($raw) ?: null) : null;
    }
}
