<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\SampleDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintSample extends Model
{
    /** @var list<string> */
    protected $fillable = ['print_job_id', 'photo_document_id', 'decision', 'channel', 'customer_comment', 'evidence_document_id', 'decided_at'];

    protected function casts(): array
    {
        return [
            'sample_no' => 'integer',
            'decision' => SampleDecision::class,
            'channel' => ApprovalChannel::class,
            'decided_at' => 'datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
