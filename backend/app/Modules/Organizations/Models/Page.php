<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Enums\PagePlatform;
use App\Modules\Pricing\Models\PriceList;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PagePlatform|null $platform
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'platform', 'external_url',
        'contact_email', 'contact_phone_e164', 'address',
        'currency', 'default_advance_percent', 'default_delivery_fee_minor',
        'invoice_prefix', 'invoice_header_name', 'invoice_address', 'invoice_phone_e164', 'logo_document_id', 'is_active',
    ];

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'platform' => PagePlatform::class,
            'default_advance_percent' => 'integer',
            'default_delivery_fee_minor' => 'integer',
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
        return $this->belongsToMany(User::class)->withPivot(['is_owner', 'created_at']);
    }

    /** @return HasMany<PriceList, $this> */
    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'logo_document_id');
    }

    /**
     * Deny by default: only assigned pages unless the user has the scope.* permission.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasAllPageAccess() ? $query : $query->whereIn($this->getTable().'.id', $user->assignedPageIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->canAccessPage($this->getKey());
    }
}
