<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    /** @var list<string> */
    protected $fillable = ['branch_id', 'holiday_date', 'name', 'created_by'];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
