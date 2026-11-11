<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\BulkProduction\Models\ProductionUnit;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The cutting job of an order (Phase 07), opened once (UNIQUE print_job_id) when the gate pass
 * is recorded after physical printing (OpenCuttingJob). The assigned cutter is `supervisor_id`.
 *
 * Status, quantities and the workflow records are written only by the Cutting Actions, under
 * the order + job row locks with an optimistic `version` check.
 *
 * @property CuttingJobStatus $status
 * @property ProductionMode|null $mode
 * @property int $version
 * @property int $planned_quantity
 * @property int $cut_quantity
 * @property int|null $supervisor_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $expected_completion_date
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class CuttingJob extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'print_job_id', 'mode', 'production_unit_id', 'supplier_id', 'gate_pass_id', 'planned_quantity', 'supervisor_id'];

    protected function casts(): array
    {
        return [
            'mode' => ProductionMode::class,
            'status' => CuttingJobStatus::class,
            'planned_quantity' => 'integer',
            'cut_quantity' => 'integer',
            'version' => 'integer',
            'supervisor_id' => 'integer',
            'print_job_id' => 'integer',
            'assigned_at' => 'datetime',
            'expected_completion_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /** @return BelongsTo<PrintJob, $this> */
    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class);
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

    /**
     * The assigned cutter.
     *
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return HasMany<CuttingJobQuantity, $this> */
    public function quantities(): HasMany
    {
        return $this->hasMany(CuttingJobQuantity::class);
    }

    /** @return HasMany<CuttingJobAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CuttingJobAssignment::class);
    }

    /** @return HasMany<CuttingJobEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CuttingJobEvent::class);
    }

    /** @return HasMany<ChecklistRun, $this> */
    public function checklistRuns(): HasMany
    {
        return $this->hasMany(ChecklistRun::class);
    }

    /** @return HasMany<ProductionIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProductionIssue::class);
    }
}
