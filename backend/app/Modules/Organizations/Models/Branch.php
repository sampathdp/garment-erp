<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Modules\BulkProduction\Models\ProductionUnit;
use App\Modules\Identity\Models\User;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'code', 'name', 'address', 'phone_e164', 'is_active'];

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return HasMany<ProductionUnit, $this> */
    public function productionUnits(): HasMany
    {
        return $this->hasMany(ProductionUnit::class);
    }

    /**
     * Deny by default: only assigned branches unless the user has the scope.* permission.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasAllBranchAccess() ? $query : $query->whereIn($this->getTable().'.id', $user->assignedBranchIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->canAccessBranch($this->getKey());
    }
}
