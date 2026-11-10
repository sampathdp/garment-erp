<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistTemplateItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['checklist_template_id', 'label', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
