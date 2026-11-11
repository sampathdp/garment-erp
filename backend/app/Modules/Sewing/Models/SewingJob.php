<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\ProductionUnit;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Sewing\Enums\SewingJobStatus;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The sewing job of an order (Phase 08, minimal step), opened once (UNIQUE cutting_job_id) when
 * cutting completes. Written only by RunSewing under the order + job locks with `version`.
 *
 * @property SewingJobStatus $status
 * @property ProductionMode|null $mode
 * @property int $version
 * @property int $received_quantity
 * @property int $sewn_quantity
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class SewingJob extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'cutting_job_id', 'mode', 'production_unit_id', 'supplier_id', 'gate_pass_id', 'received_quantity', 'supervisor_id'];

    protected function casts(): array
    {
        return [
            'mode' => ProductionMode::class,
            'status' => SewingJobStatus::class,
            'received_quantity' => 'integer',
            'sewn_quantity' => 'integer',
            'version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
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

    /** @return BelongsTo<CuttingJob, $this> */
    public function cuttingJob(): BelongsTo
    {
        return $this->belongsTo(CuttingJob::class);
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

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
