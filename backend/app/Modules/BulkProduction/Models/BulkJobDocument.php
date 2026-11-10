<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\BulkDocumentKind;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property BulkDocumentKind $kind
 */
class BulkJobDocument extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_job_id', 'document_id', 'kind', 'note'];

    protected function casts(): array
    {
        return ['kind' => BulkDocumentKind::class, 'document_id' => 'integer'];
    }

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
