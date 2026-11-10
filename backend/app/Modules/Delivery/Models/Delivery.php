<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Models;

use App\Modules\Delivery\Enums\CodStatus;
use App\Modules\Delivery\Enums\DeliveryMethod;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Payments\Models\Payment;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BranchScoped;

    protected $table = 'deliveries';

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'method', 'courier_name', 'tracking_no', 'scheduled_date', 'received_by_name', 'notes'];

    protected function casts(): array
    {
        return [
            'method' => DeliveryMethod::class,
            'status' => DeliveryStatus::class,
            'cod_status' => CodStatus::class,
            'scheduled_date' => 'date',
            'cod_amount_minor' => 'integer',
            'cod_collected_minor' => 'integer',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
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
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'proof_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<GatePass, $this> */
    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }
}
