<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Support\Roles;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with management metadata. `is_system` is never mass assignable: only the
 * PermissionSeeder marks seeded roles as system roles.
 *
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    /** @var list<string> */
    protected $fillable = ['name', 'guard_name', 'label', 'description'];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        // Roles always belong to the `web` (session) guard. Without this, Spatie would use the
        // request's current default guard, which `auth:sanctum` switches to `sanctum`.
        $attributes['guard_name'] ??= self::GUARD;

        parent::__construct($attributes);

        // Spatie defaults to $guarded = [] (+ id). Everything outside $fillable stays guarded.
        $this->guarded = ['*'];
    }

    public const GUARD = 'web';

    public static function findByName(string $name, ?string $guardName = null): RoleContract
    {
        return parent::findByName($name, $guardName ?? self::GUARD);
    }

    public static function findById(int|string $id, ?string $guardName = null): RoleContract
    {
        return parent::findById($id, $guardName ?? self::GUARD);
    }

    public static function findOrCreate(string $name, ?string $guardName = null): RoleContract
    {
        return parent::findOrCreate($name, $guardName ?? self::GUARD);
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function isLocked(): bool
    {
        return in_array($this->name, Roles::LOCKED, true);
    }

    /** @return array{name: string, label: string} */
    public static function ref(Model $role): array
    {
        $name = (string) $role->getAttribute('name');

        return ['name' => $name, 'label' => $role instanceof self ? $role->displayName() : $name];
    }

    public function displayName(): string
    {
        return $this->label ?? ucwords(str_replace('_', ' ', $this->name));
    }
}
