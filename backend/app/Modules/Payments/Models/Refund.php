<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    /** @var list<string> */
    protected $fillable = ['payment_id', 'amount_minor', 'reason', 'method', 'reference_no', 'refunded_at'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
