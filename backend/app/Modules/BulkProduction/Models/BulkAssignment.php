<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Enums\WorkStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property WorkStatus $status
 */
class BulkAssignment extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'mode', 'production_unit_id', 'supplier_id', 'bulk_designer_id', 'quantity', 'due_date'];

    protected function casts(): array
    {
        return [
            'mode' => ProductionMode::class,
            'status' => WorkStatus::class,
            'quantity' => 'integer',
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return HasMany<BulkMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(BulkMeasurement::class);
    }

    /** @return BelongsToMany<Document, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'bulk_assignment_documents');
    }
}
