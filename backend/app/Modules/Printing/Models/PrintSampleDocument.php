<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Documents\Models\Document;
use App\Modules\Printing\Enums\SampleFileKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file of a printing sample version: the sample image or a related document. Immutable.
 *
 * @property SampleFileKind $kind
 */
class PrintSampleDocument extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['print_sample_id', 'document_id', 'kind'];

    protected function casts(): array
    {
        return ['kind' => SampleFileKind::class];
    }

    /** @return BelongsTo<PrintSample, $this> */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(PrintSample::class, 'print_sample_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
