<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Orders\Enums\Department;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionUnit extends Model
{
    use BranchScoped;

    /** @var list<string> */
    protected $fillable = ['branch_id', 'name', 'department', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'department' => Department::class,
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
