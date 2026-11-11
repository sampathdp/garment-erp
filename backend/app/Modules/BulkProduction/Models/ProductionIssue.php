<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\Cutting\Enums\GarmentPart;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A production issue (Phase 07 central register): category, description, quantity affected,
 * optional image, reporter, assignee, resolution and status. Written only by the issue Actions
 * (ReportProductionIssue, ManageProductionIssue) with an optimistic `version`.
 *
 * `is_blocking` + an unresolved status blocks cutting completion and job completion.
 *
 * @property ChecklistStage $stage
 * @property IssueType $type
 * @property IssueStatus $status
 * @property GarmentPart|null $part
 * @property int|null $quantity_affected
 * @property bool $is_blocking
 * @property int $version
 * @property int|null $cutting_job_id
 * @property int|null $assigned_to
 * @property int $raised_by
 * @property Carbon|null $assigned_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 */
class ProductionIssue extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'cutting_job_id', 'stage', 'type', 'description', 'quantity_affected', 'part', 'size_label'];

    protected function casts(): array
    {
        return [
            'stage' => ChecklistStage::class,
            'type' => IssueType::class,
            'status' => IssueStatus::class,
            'part' => GarmentPart::class,
            'quantity_affected' => 'integer',
            'is_blocking' => 'boolean',
            'version' => 'integer',
            'cutting_job_id' => 'integer',
            'assigned_to' => 'integer',
            'raised_by' => 'integer',
            'image_document_id' => 'integer',
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Unresolved blocking issues.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('production_issues.is_blocking', true)->whereIn('production_issues.status', IssueStatus::unresolvedValues());
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

    /** @return BelongsTo<CuttingJob, $this> */
    public function cuttingJob(): BelongsTo
    {
        return $this->belongsTo(CuttingJob::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'image_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<ProductionIssueEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProductionIssueEvent::class);
    }
}
