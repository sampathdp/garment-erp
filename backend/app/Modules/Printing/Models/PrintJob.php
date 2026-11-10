<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\ProductionUnit;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PrintJobStatus $status
 */
class PrintJob extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'design_version_id', 'garment_list_version_id', 'mode', 'production_unit_id', 'supplier_id', 'gate_pass_id'];

    protected function casts(): array
    {
        return [
            'mode' => ProductionMode::class,
            'status' => PrintJobStatus::class,
            'printed_quantity' => 'integer',
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

    /** @return BelongsTo<DesignVersion, $this> */
    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class);
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function garmentListVersion(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class);
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

    /** @return BelongsTo<GatePass, $this> */
    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    /** @return HasMany<PrintSample, $this> */
    public function samples(): HasMany
    {
        return $this->hasMany(PrintSample::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
