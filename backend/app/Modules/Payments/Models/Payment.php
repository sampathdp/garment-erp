<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PaymentStatus $status
 * @property PaymentMethod $method
 * @property Carbon $paid_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /**
     * status / verification / branch_id / recorded_by are set by Actions only.
     *
     * @var list<string>
     */
    protected $fillable = ['order_id', 'amount_minor', 'currency', 'method', 'reference_no', 'paid_at', 'slip_document_id', 'delivery_id'];

    /** Methods that always need a slip or screenshot as evidence. */
    public const EVIDENCE_REQUIRED = [PaymentMethod::BankTransfer, PaymentMethod::Online, PaymentMethod::Cheque];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function slip(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'slip_document_id');
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<PaymentVerificationEvent, $this> */
    public function verificationEvents(): HasMany
    {
        return $this->hasMany(PaymentVerificationEvent::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
