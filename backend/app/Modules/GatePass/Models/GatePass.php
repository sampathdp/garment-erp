<?php

declare(strict_types=1);

namespace App\Modules\GatePass\Models;

use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\GatePass\Enums\GatePassStatus;
use App\Modules\GatePass\Enums\GatePassType;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatePass extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    protected bool $orderIsOptional = true;

    /** @var list<string> */
    protected $fillable = ['branch_id', 'type', 'order_id', 'supplier_id', 'delivery_id', 'purpose', 'vehicle_no', 'carrier_name', 'expected_return_date'];

    protected function casts(): array
    {
        return [
            'type' => GatePassType::class,
            'status' => GatePassStatus::class,
            'expected_return_date' => 'date',
            'approved_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return HasMany<GatePassLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GatePassLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
