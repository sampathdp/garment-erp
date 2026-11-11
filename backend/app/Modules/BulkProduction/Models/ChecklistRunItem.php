<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ChecklistResult;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ChecklistResult|null $result
 * @property string $label
 * @property bool $is_required
 * @property bool $is_completed
 * @property int $sort_order
 * @property Carbon|null $completed_at
 */
class ChecklistRunItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['checklist_run_id', 'checklist_template_item_id', 'result', 'note', 'label', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return [
            'result' => ChecklistResult::class,
            'is_required' => 'boolean',
            'is_completed' => 'boolean',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
            'checklist_run_id' => 'integer',
            'responsible_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return BelongsTo<ChecklistRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ChecklistRun::class, 'checklist_run_id');
    }

    /** @return BelongsTo<ChecklistTemplateItem, $this> */
    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class, 'checklist_template_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
