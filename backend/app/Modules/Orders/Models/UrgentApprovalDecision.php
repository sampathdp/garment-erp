<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One approval slot's decision on an urgent request. Decisions are final (append-only).
 *
 * @property UrgentApprovalSlot $slot
 * @property UrgentDecision $decision
 * @property Carbon|null $decided_at
 */
class UrgentApprovalDecision extends Model
{
    /** @var list<string> */
    protected $fillable = ['urgent_approval_id', 'slot', 'decision', 'reason', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return [
            'slot' => UrgentApprovalSlot::class,
            'decision' => UrgentDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }

    /** @return BelongsTo<UrgentApproval, $this> */
    public function urgentApproval(): BelongsTo
    {
        return $this->belongsTo(UrgentApproval::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
