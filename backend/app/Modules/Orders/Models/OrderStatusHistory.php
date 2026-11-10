<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property Carbon|null $created_at
 */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['order_id', 'from_status', 'to_status', 'reason', 'actor_id'];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
