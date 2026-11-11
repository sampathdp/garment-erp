<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkVersion;
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
use Illuminate\Support\Carbon;

/**
 * The printing job of an order (Phase 06), opened once (UNIQUE bulk_job_id) when the bulk job
 * is completed (OpenPrintJob). It references the approved design version, the locked list
 * version and the final bulk version the printing is made from.
 *
 * Status and the workflow records are written only by the Printing Actions, under the order +
 * job row locks with an optimistic `version` check.
 *
 * @property PrintJobStatus $status
 * @property ProductionMode $mode
 * @property int $version
 * @property Carbon|null $sent_at
 * @property Carbon|null $printing_started_at
 * @property Carbon|null $printing_completed_at
 */
class PrintJob extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'bulk_job_id', 'design_version_id', 'garment_list_version_id', 'bulk_version_id', 'mode', 'production_unit_id', 'supplier_id', 'gate_pass_id'];

    protected function casts(): array
    {
        return [
            'mode' => ProductionMode::class,
            'status' => PrintJobStatus::class,
            'printed_quantity' => 'integer',
            'version' => 'integer',
            'supplier_id' => 'integer',
            'approved_sample_id' => 'integer',
            'sent_at' => 'datetime',
            'printing_started_at' => 'datetime',
            'printing_completed_at' => 'datetime',
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

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<BulkVersion, $this> */
    public function bulkVersion(): BelongsTo
    {
        return $this->belongsTo(BulkVersion::class);
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
        return $this->belongsTo(Supplier::class)->withTrashed();
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

    /** @return BelongsTo<PrintSample, $this> */
    public function approvedSample(): BelongsTo
    {
        return $this->belongsTo(PrintSample::class, 'approved_sample_id');
    }

    /** @return HasMany<PrintJobEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class);
    }

    /** @return HasMany<PrintJobGatePass, $this> */
    public function gatePasses(): HasMany
    {
        return $this->hasMany(PrintJobGatePass::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function printingStarter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printing_started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function printingCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printing_completed_by');
    }
}
