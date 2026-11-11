<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\BulkProduction\Models\BulkAssignment;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Customers\Models\Customer;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Documents\Models\Document;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pricing\Models\Material;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\QualityControl\Models\QcInspection;
use App\Modules\Sewing\Models\SewingJob;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property OrderStatus $status
 * @property OrderPaymentStatus $payment_status
 * @property UrgentStatus $urgent_status
 * @property Carbon $required_delivery_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $details_submitted_at
 */
class Order extends Model
{
    use BranchScoped;

    protected bool $scopeByPage = true;

    /**
     * status, payment_status, totals, urgency and version are NOT fillable: they change only through Actions (forceFill inside a transaction).
     *
     * @var list<string>
     */
    protected $fillable = ['order_no', 'branch_id', 'page_id', 'customer_id', 'order_type_id', 'sales_owner_id', 'required_delivery_date', 'currency', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'status_before_hold' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'urgent_status' => UrgentStatus::class,
            'required_delivery_date' => 'date',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_urgent' => 'boolean',
            'total_quantity' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'advance_percent' => 'integer',
            'advance_required_minor' => 'integer',
            'verified_paid_minor' => 'integer',
            'version' => 'integer',
            'material_id' => 'integer',
            'details_submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function designAttachment(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'design_attachment_document_id');
    }

    /** @return HasOne<DesignTask, $this> */
    public function designTask(): HasOne
    {
        return $this->hasOne(DesignTask::class); // one design task per order (CreateDesignTask)
    }

    /** @return HasOne<BulkJob, $this> */
    public function bulkJob(): HasOne
    {
        return $this->hasOne(BulkJob::class);
    }

    /** Additional order information (fabric, design instructions ...) is available from payment approval. */
    public function acceptsJobDetails(): bool
    {
        return in_array($this->status, [OrderStatus::Confirmed, OrderStatus::DesignInProgress, OrderStatus::DesignReview], true);
    }

    /** @return BelongsTo<OrderType, $this> */
    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    /** Verified advance still missing before the order may be released to production (minor units, ≥ 0). */
    public function advanceShortfallMinor(): int
    {
        return max(0, $this->advance_required_minor - $this->verified_paid_minor);
    }

    /**
     * Payment condition for production: verified payments cover the required advance.
     * Separate from payment verification (a verified payment may still be below 30%) and from
     * release (an urgent order also needs both approvals).
     */
    public function meetsAdvanceRequirement(): bool
    {
        return $this->total_minor > 0 && $this->verified_paid_minor >= $this->advance_required_minor;
    }

    /** Released (Approved Order or later) AND the advance is still covered. */
    public function isProductionEligible(): bool
    {
        return $this->status->isReleased() && $this->meetsAdvanceRequirement();
    }

    /** @return BelongsTo<User, $this> */
    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<UrgentApproval, $this> */
    public function urgentApprovals(): HasMany
    {
        return $this->hasMany(UrgentApproval::class);
    }

    /**
     * The current (not superseded) urgent approval request, if any.
     *
     * @return HasOne<UrgentApproval, $this>
     */
    public function currentUrgentApproval(): HasOne
    {
        return $this->hasOne(UrgentApproval::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereNull('superseded_at'));
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<DesignTask, $this> */
    public function designTasks(): HasMany
    {
        return $this->hasMany(DesignTask::class);
    }

    /** @return HasMany<BulkAssignment, $this> */
    public function bulkAssignments(): HasMany
    {
        return $this->hasMany(BulkAssignment::class);
    }

    /** @return HasMany<PrintJob, $this> */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /** @return HasMany<CuttingJob, $this> */
    public function cuttingJobs(): HasMany
    {
        return $this->hasMany(CuttingJob::class);
    }

    /** @return HasMany<SewingJob, $this> */
    public function sewingJobs(): HasMany
    {
        return $this->hasMany(SewingJob::class);
    }

    /** @return HasMany<QcInspection, $this> */
    public function qcInspections(): HasMany
    {
        return $this->hasMany(QcInspection::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** @return HasMany<GatePass, $this> */
    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    /** @return HasMany<ProductionIssue, $this> */
    public function productionIssues(): HasMany
    {
        return $this->hasMany(ProductionIssue::class);
    }

    /** @return HasMany<ChecklistRun, $this> */
    public function checklistRuns(): HasMany
    {
        return $this->hasMany(ChecklistRun::class);
    }
}
