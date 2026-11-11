<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A checklist instance. For bulk jobs (bulk_job_id set) the items are snapshots of the template
 * taken when the job was assigned, so later template changes never alter an existing job.
 *
 * @property ChecklistStage $stage
 * @property Carbon|null $completed_at
 * @property Carbon|null $superseded_at
 * @property Carbon|null $client_confirmed_at
 * @property ApprovalChannel|null $client_channel
 */
class ChecklistRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['checklist_template_id', 'order_id', 'bulk_job_id', 'cutting_job_id', 'stage'];

    protected function casts(): array
    {
        return [
            'stage' => ChecklistStage::class,
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'client_channel' => ApprovalChannel::class,
            'bulk_job_id' => 'integer',
            'cutting_job_id' => 'integer',
        ];
    }

    public function isClientConfirmed(): bool
    {
        return $this->client_confirmed_at !== null;
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<CuttingJob, $this> */
    public function cuttingJob(): BelongsTo
    {
        return $this->belongsTo(CuttingJob::class);
    }

    /** @return HasMany<ChecklistRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistRunItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function clientConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_confirmed_by');
    }
}
