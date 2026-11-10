<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * Account state (is_active, session_version, password_changed_at, deactivated_at) is not mass
 * assignable: it changes only through Identity Actions.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone_e164
 * @property bool $is_active
 * @property int $session_version
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected string $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'phone_e164', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @var array<int>|null Per-instance memo; reset with refreshAccessScope(). */
    private ?array $branchIdsMemo = null;

    /** @var array<int>|null */
    private ?array $pageIdsMemo = null;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('created_at');
    }

    /** @return BelongsToMany<Page, $this> */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class)->withPivot(['is_owner', 'created_at']);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function hasAllBranchAccess(): bool
    {
        return $this->can(Permissions::SCOPE_ALL_BRANCHES);
    }

    public function hasAllPageAccess(): bool
    {
        return $this->can(Permissions::SCOPE_ALL_PAGES);
    }

    public function hasFullDataScope(): bool
    {
        return $this->hasAllBranchAccess() && $this->hasAllPageAccess();
    }

    /** @return array<int> */
    public function assignedBranchIds(): array
    {
        return $this->branchIdsMemo ??= $this->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int> */
    public function assignedPageIds(): array
    {
        return $this->pageIdsMemo ??= $this->pages()->pluck('pages.id')->map(fn ($id) => (int) $id)->all();
    }

    public function canAccessBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return $this->hasAllBranchAccess() || in_array($branchId, $this->assignedBranchIds(), true);
    }

    public function canAccessPage(?int $pageId): bool
    {
        if ($pageId === null) {
            return false;
        }

        return $this->hasAllPageAccess() || in_array($pageId, $this->assignedPageIds(), true);
    }

    /** Page Owner of the page (page_user.is_owner) — data, not a role name. */
    public function ownsPage(?int $pageId): bool
    {
        if ($pageId === null) {
            return false;
        }

        return $this->pages()->where('pages.id', $pageId)->wherePivot('is_owner', true)->exists();
    }

    public function refreshAccessScope(): void
    {
        $this->branchIdsMemo = null;
        $this->pageIdsMemo = null;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * Users an administrator may see. Deny by default:
     *  - global scope (all branches + all pages): everyone;
     *  - restricted on one dimension only: users sharing an assigned branch (or page) of that dimension;
     *  - restricted on both: users sharing an assigned branch or an assigned page.
     * The administrator always sees themself.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->hasFullDataScope()) {
            return $query;
        }

        $branchIds = $actor->hasAllBranchAccess() ? null : $actor->assignedBranchIds();
        $pageIds = $actor->hasAllPageAccess() ? null : $actor->assignedPageIds();

        return $query->where(function (Builder $q) use ($actor, $branchIds, $pageIds): void {
            $q->whereKey($actor->getKey());
            if ($branchIds !== null) {
                $q->orWhereHas('branches', fn (Builder $b) => $b->whereIn('branches.id', $branchIds));
            }
            if ($pageIds !== null) {
                $q->orWhereHas('pages', fn (Builder $p) => $p->whereIn('pages.id', $pageIds));
            }
        });
    }

    public function isVisibleTo(User $actor): bool
    {
        if ($actor->hasFullDataScope() || $actor->is($this)) {
            return true;
        }

        return static::query()->whereKey($this->getKey())->visibleTo($actor)->exists();
    }
}
