<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use Database\Seeders\ProductionBootstrapSeeder;

function withBootstrapEnv(array $values, Closure $run): void
{
    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        $run();
    } finally {
        foreach (array_keys($values) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}

it('creates the organization, working week and one Super Admin idempotently', function () {
    withBootstrapEnv(['SEED_ORG_NAME' => 'Test Garments', 'SEED_ADMIN_EMAIL' => 'Owner@Example.test', 'SEED_ADMIN_PASSWORD' => ''], function () {
        $this->seed(ProductionBootstrapSeeder::class);

        $org = Organization::query()->sole();
        WorkingCalendarDay::query()->where('organization_id', $org->id)->where('iso_weekday', 6)->update(['is_working_day' => false]);

        $this->seed(ProductionBootstrapSeeder::class);
    });

    $org = Organization::query()->sole();
    $admin = User::query()->sole();

    expect($org->name)->toBe('Test Garments')
        ->and($org->legal_name)->toBe('Test Garments')
        ->and(WorkingCalendarDay::query()->where('organization_id', $org->id)->count())->toBe(7)
        ->and(WorkingCalendarDay::query()->where('iso_weekday', 7)->value('is_working_day'))->toBeFalse()
        // A re-run keeps the configuration the client changed.
        ->and(WorkingCalendarDay::query()->where('iso_weekday', 6)->value('is_working_day'))->toBeFalse()
        ->and($admin->email)->toBe('owner@example.test')
        ->and($admin->hasRole(Roles::SUPER_ADMIN))->toBeTrue();
});

it('does nothing without an organization name and a valid admin email', function () {
    withBootstrapEnv(['SEED_ORG_NAME' => '', 'SEED_ADMIN_EMAIL' => 'not-an-email'], function () {
        $this->seed(ProductionBootstrapSeeder::class);
    });

    expect(Organization::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});
