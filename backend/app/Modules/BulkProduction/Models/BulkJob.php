<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The bulk task of an order, created once (UNIQUE order_id) when the final design, the locked
 * list, the invoice and the advance are all in place (CreateBulkJobIfEligible). The Operation
 * Admin then assigns it to an internal Bulk Designer or to an external producer (Phase 05).
 *
 * Status, assignment, review pointers and completion are written only by the BulkProduction
 * Actions, under a row lock with an optimistic `version` check.
 *
 * @property BulkJobStatus $status
 * @property ProductionMode|null $mode
 * @property int $version
 * @property int $correction_rounds
 * @property Carbon|null $assigned_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 */
class BulkJob extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'branch_id', 'design_task_id', 'garment_list_version_id', 'invoice_id', 'bulk_designer_id'];

    protected function casts(): array
    {
        return [
            'status' => BulkJobStatus::class,
            'mode' => ProductionMode::class,
            'bulk_designer_id' => 'integer',
            'supplier_id' => 'integer',
            'garment_list_version_id' => 'integer',
            'approved_list_version_id' => 'integer',
            'current_version_id' => 'integer',
            'approved_version_id' => 'integer',
            'version' => 'integer',
            'correction_rounds' => 'integer',
            'assigned_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isInternal(): bool
    {
        return $this->mode === ProductionMode::Internal;
    }

    public function isExternal(): bool
    {
        return $this->mode === ProductionMode::External;
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

    /** @return BelongsTo<DesignTask, $this> */
    public function designTask(): BelongsTo
    {
        return $this->belongsTo(DesignTask::class);
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function garmentListVersion(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class);
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function approvedListVersion(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class, 'approved_list_version_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bulkDesigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bulk_designer_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<BulkVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BulkVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<BulkVersion, $this> */
    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(BulkVersion::class, 'approved_version_id');
    }

    /** @return HasMany<BulkVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BulkVersion::class);
    }

    /** @return HasMany<BulkJobAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(BulkJobAssignment::class);
    }

    /** @return HasMany<BulkJobMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(BulkJobMeasurement::class);
    }

    /** @return HasMany<BulkJobMeasurementChange, $this> */
    public function measurementChanges(): HasMany
    {
        return $this->hasMany(BulkJobMeasurementChange::class);
    }

    /** @return HasMany<BulkJobDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(BulkJobDocument::class);
    }

    /**
     * The current (not superseded) checklist instance.
     *
     * @return HasOne<ChecklistRun, $this>
     */
    public function checklist(): HasOne
    {
        return $this->hasOne(ChecklistRun::class)->whereNull('superseded_at');
    }
}
