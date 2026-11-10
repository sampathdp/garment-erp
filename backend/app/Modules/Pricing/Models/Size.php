<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Size extends Model
{
    /** @var list<string> */
    protected $fillable = ['size_chart_id', 'code', 'label', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<SizeChart, $this> */
    public function sizeChart(): BelongsTo
    {
        return $this->belongsTo(SizeChart::class);
    }
}
