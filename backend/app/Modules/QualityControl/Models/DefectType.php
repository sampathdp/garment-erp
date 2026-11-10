<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Models;

use Illuminate\Database\Eloquent\Model;

class DefectType extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
