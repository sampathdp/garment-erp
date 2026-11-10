<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingCalendarDay extends Model
{
    /** @var list<string> */
    protected $fillable = ['organization_id', 'iso_weekday', 'is_working_day'];

    protected function casts(): array
    {
        return [
            'iso_weekday' => 'integer',
            'is_working_day' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
