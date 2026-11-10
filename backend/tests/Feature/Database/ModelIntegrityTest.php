<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Support\Database\MorphMap;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function moduleModels(): array
{
    return collect(glob(app_path('Modules/*/Models/*.php')))
        ->map(function (string $file) {
            $module = basename(dirname($file, 2));

            return "App\\Modules\\{$module}\\Models\\".basename($file, '.php');
        })
        ->all();
}

it('declares an explicit, non-empty fillable list on every model (no $guarded = [])', function () {
    foreach (moduleModels() as $class) {
        /** @var Model $model */
        $model = new $class;
        expect($model->getFillable())->not->toBeEmpty("{$class} has no \$fillable")
            ->and($model->getGuarded())->toBe(['*'], "{$class} must not override \$guarded");
    }
});

it('maps every model to a table that exists and to a morph alias', function () {
    $mapped = array_values(MorphMap::map());

    foreach (moduleModels() as $class) {
        expect(Schema::hasTable((new $class)->getTable()))->toBeTrue("Table for {$class} missing")
            ->and(in_array($class, $mapped, true))->toBeTrue("{$class} missing from MorphMap");
    }
});

it('keeps workflow and financial fields out of mass assignment on orders', function () {
    $fillable = (new Order)->getFillable();

    foreach (['status', 'payment_status', 'total_minor', 'verified_paid_minor', 'advance_percent', 'advance_required_minor', 'is_urgent', 'urgent_status', 'version'] as $guarded) {
        expect($fillable)->not->toContain($guarded);
    }
});

it('throws instead of silently discarding non-fillable attributes outside production', function () {
    expect(fn () => new Branch(['name' => 'X', 'is_admin' => true]))->toThrow(MassAssignmentException::class);
});

it('enforces foreign keys', function () {
    expect(fn () => DB::table('branches')->insert(['organization_id' => 999_999, 'code' => 'X1', 'name' => 'Orphan', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

it('enforces unique business numbers', function () {
    $branch = Branch::factory()->create();

    expect(fn () => DB::table('branches')->insert(['organization_id' => $branch->organization_id, 'code' => $branch->code, 'name' => 'Duplicate', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});
