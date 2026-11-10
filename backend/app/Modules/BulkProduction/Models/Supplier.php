<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\SupplierService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 * @property SupplierService $service_type
 * @property bool $is_active
 */
class Supplier extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'phone_e164', 'email', 'address', 'service_type', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_type' => SupplierService::class,
        ];
    }
}
