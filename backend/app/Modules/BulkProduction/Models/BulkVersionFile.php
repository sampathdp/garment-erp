<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\BulkFileKind;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property BulkFileKind $kind
 */
class BulkVersionFile extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['bulk_version_id', 'document_id', 'kind'];

    protected function casts(): array
    {
        return ['kind' => BulkFileKind::class, 'document_id' => 'integer'];
    }

    /** @return BelongsTo<BulkVersion, $this> */
    public function bulkVersion(): BelongsTo
    {
        return $this->belongsTo(BulkVersion::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
