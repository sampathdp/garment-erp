<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Models;

use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery document (delivery sheet, waybill, delivery / customer photo): type, file
 * reference (private disk), uploader and upload time. Append-only.
 *
 * @property DeliveryDocumentType $type
 */
class DeliveryDocument extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['delivery_id', 'document_id', 'type', 'notes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['type' => DeliveryDocumentType::class, 'document_id' => 'integer', 'delivery_id' => 'integer'];
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
