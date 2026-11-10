<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Models;

use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\QualityControl\Enums\DefectAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcDefect extends Model
{
    /** @var list<string> */
    protected $fillable = ['qc_inspection_id', 'defect_type_id', 'quantity', 'garment_list_item_id', 'action', 'note'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'action' => DefectAction::class,
        ];
    }

    /** @return BelongsTo<QcInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }

    /** @return BelongsTo<DefectType, $this> */
    public function defectType(): BelongsTo
    {
        return $this->belongsTo(DefectType::class);
    }

    /** @return BelongsTo<GarmentListItem, $this> */
    public function garmentListItem(): BelongsTo
    {
        return $this->belongsTo(GarmentListItem::class);
    }
}
