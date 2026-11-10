<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\UrgentDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One urgent-approval request: created when a submitted order is fewer than 7 days from its due
 * date, superseded when the due date changes. `decision` is the overall outcome: approved once
 * every slot approved, rejected as soon as one slot rejected.
 *
 * @property UrgentDecision|null $decision
 * @property Carbon $evaluated_delivery_date
 * @property Carbon|null $reference_date
 * @property Carbon|null $requested_at
 * @property Carbon|null $superseded_at
 */
class UrgentApproval extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_id', 'evaluated_delivery_date', 'reference_date', 'working_days_available', 'requested_at'];

    protected function casts(): array
    {
        return [
            'evaluated_delivery_date' => 'date',
            'reference_date' => 'date',
            'working_days_available' => 'integer',
            'requested_at' => 'datetime',
            'decision' => UrgentDecision::class,
            'decided_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->decision === null && $this->superseded_at === null;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<UrgentApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(UrgentApprovalDecision::class);
    }
}
