<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\SampleDecision;
use App\Modules\Printing\Enums\SampleReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One immutable printing sample version (sample_no 1, 2, …). A correction after a rejection is
 * a NEW row; earlier samples and their decisions are never overwritten. The decision (approve,
 * or reject with a reason) is recorded once on the row by the Page Owner.
 *
 * @property int $sample_no
 * @property SampleDecision|null $decision
 * @property ApprovalChannel|null $channel
 * @property Carbon|null $decided_at
 */
class PrintSample extends Model
{
    /** @var list<string> */
    protected $fillable = ['print_job_id', 'photo_document_id', 'notes'];

    protected function casts(): array
    {
        return [
            'sample_no' => 'integer',
            'decision' => SampleDecision::class,
            'channel' => ApprovalChannel::class,
            'decided_at' => 'datetime',
        ];
    }

    public function reviewStatus(): SampleReviewStatus
    {
        return SampleReviewStatus::fromDecision($this->decision);
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'photo_document_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    /** @return HasMany<PrintSampleDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PrintSampleDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The staff member who recorded the review decision.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
