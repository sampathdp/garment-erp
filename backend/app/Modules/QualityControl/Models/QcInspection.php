<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\QualityControl\Enums\QcResult;
use App\Modules\QualityControl\Enums\QcStage;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcInspection extends Model
{
    use BranchScoped;

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'stage', 'quantity_inspected', 'quantity_passed', 'quantity_failed', 'inspected_at'];

    protected function casts(): array
    {
        return [
            'stage' => QcStage::class,
            'result' => QcResult::class,
            'quantity_inspected' => 'integer',
            'quantity_passed' => 'integer',
            'quantity_failed' => 'integer',
            'inspected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** @return HasMany<QcDefect, $this> */
    public function defects(): HasMany
    {
        return $this->hasMany(QcDefect::class);
    }
}
