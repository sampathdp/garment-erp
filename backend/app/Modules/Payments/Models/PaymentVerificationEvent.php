<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentVerificationAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only verification history of a payment.
 *
 * @property PaymentVerificationAction $action
 * @property Carbon|null $created_at
 */
class PaymentVerificationEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'action', 'notes', 'actor_id'];

    protected function casts(): array
    {
        return [
            'action' => PaymentVerificationAction::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
