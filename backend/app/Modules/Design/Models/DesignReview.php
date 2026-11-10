<?php

declare(strict_types=1);

namespace App\Modules\Design\Models;

use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Design\Enums\ReviewDecision;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property ReviewDecision $decision
 * @property ApprovalChannel $channel
 * @property Carbon|null $decided_at
 */
class DesignReview extends Model
{
    /** @var list<string> */
    protected $fillable = ['design_version_id', 'decision', 'channel', 'customer_comment', 'evidence_document_id', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'channel' => ApprovalChannel::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class);
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

    /** @return HasMany<DesignCorrectionRequest, $this> */
    public function correctionRequests(): HasMany
    {
        return $this->hasMany(DesignCorrectionRequest::class);
    }
}
