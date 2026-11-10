<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a branch or a page. Both are never deleted once created, only deactivated
 * (`is_active`), because orders, invoices and gate passes reference them.
 */
final class SaveOrganizationUnit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T of Branch|Page
     *
     * @param  class-string<T>  $class
     * @param  T|null  $unit
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    public function handle(User $actor, string $class, Branch|Page|null $unit, array $attributes): Branch|Page
    {
        return DB::transaction(function () use ($actor, $class, $unit, $attributes): Branch|Page {
            $creating = $unit === null;
            if ($creating) {
                $organizationId = Organization::query()->orderBy('id')->value('id')
                    ?? throw ApiException::conflict('organization_missing', 'The company record has not been set up yet.');
                $unit = new $class(['organization_id' => $organizationId]);
            }

            $before = $creating ? [] : $unit->only(array_keys($attributes));
            $unit->fill($attributes);
            $dirty = $unit->getDirty();
            if (! $creating && $dirty === []) {
                return $unit;
            }
            $unit->save();
            if ($creating) {
                $unit->refresh(); // database defaults (currency, advance percent, ...)
            }

            $prefix = $unit instanceof Branch ? 'branches' : 'pages';
            $action = $creating ? 'created' : (array_key_exists('is_active', $dirty) ? ($unit->is_active ? 'activated' : 'deactivated') : 'updated');

            $this->audit->record("{$prefix}.{$action}", $unit, [
                'before' => array_intersect_key($before, $dirty),
                'after' => $dirty,
            ], actor: $actor, branchId: $unit instanceof Branch ? $unit->id : null);

            return $unit;
        });
    }
}
