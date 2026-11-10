<?php

declare(strict_types=1);

namespace App\Modules\Design\Models;

use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DesignFileKind $kind
 */
class DesignVersionFile extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['design_version_id', 'document_id', 'kind'];

    protected function casts(): array
    {
        return [
            'kind' => DesignFileKind::class,
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
