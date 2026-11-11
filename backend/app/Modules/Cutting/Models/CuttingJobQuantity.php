<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expected (from the locked name/number list) and cut quantity of one size.
 * Missing = expected − cut. 0 ≤ cut ≤ expected is enforced by the Action (and a CHECK on MySQL).
 *
 * @property string $size_label
 * @property int $expected_quantity
 * @property int $cut_quantity
 * @property int $sort_order
 */
class CuttingJobQuantity extends Model
{
    /** @var list<string> */
    protected $fillable = ['cutting_job_id', 'size_id', 'size_label', 'sort_order', 'expected_quantity'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'integer', 'cut_quantity' => 'integer', 'sort_order' => 'integer', 'size_id' => 'integer', 'cutting_job_id' => 'integer'];
    }

    public function missing(): int
    {
        return max(0, $this->expected_quantity - $this->cut_quantity);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
