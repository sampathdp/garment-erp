<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/** Configurable order type ("Single Order", "Bulk Order", ...). Deactivated, never deleted once used. */
class OrderType extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
