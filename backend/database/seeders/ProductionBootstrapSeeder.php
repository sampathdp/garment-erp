<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * First-run setup for a new production database (DEPLOYMENT_GUIDE.md). Idempotent, safe to re-run.
 *
 *   SEED_ORG_NAME="..." SEED_ADMIN_EMAIL="..." php artisan db:seed --class=ProductionBootstrapSeeder --force
 *
 * Creates permissions/roles, catalog reference data, the organization, its working week and one
 * Super Admin. Branches, pages, sizes, fabrics and prices are configured in the app afterwards.
 * Leave SEED_ADMIN_PASSWORD empty: a random password is printed once and must be changed.
 */
class ProductionBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $orgName = trim((string) env('SEED_ORG_NAME', ''));
        $email = Str::lower(trim((string) env('SEED_ADMIN_EMAIL', '')));

        if ($orgName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->command->error('Set SEED_ORG_NAME and a valid SEED_ADMIN_EMAIL before running this seeder (and run `php artisan config:clear` if the config is cached).');

            return;
        }

        $this->call(PermissionSeeder::class);
        $this->call(CatalogSeeder::class);

        $org = Organization::query()->firstOrCreate(['name' => $orgName], [
            'legal_name' => trim((string) env('SEED_ORG_LEGAL_NAME', '')) ?: $orgName,
            'default_currency' => 'LKR',
        ]);

        // ASSUMPTION (client question Q22 open): Monday to Saturday working, Sunday off.
        // Existing rows are never overwritten, so a re-run keeps the client's configuration.
        foreach (range(1, 7) as $day) {
            WorkingCalendarDay::query()->firstOrCreate(
                ['organization_id' => $org->id, 'iso_weekday' => $day],
                ['is_working_day' => $day !== 7],
            );
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->command->info("Admin {$email} already exists; left unchanged.");

            return;
        }

        $password = (string) env('SEED_ADMIN_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20);
        }

        $admin = User::query()->create(['name' => 'System Owner', 'email' => $email, 'password' => $password]);
        $admin->assignRole(Roles::SUPER_ADMIN);

        $this->command->info("Super Admin created: {$email}".($generated ? " / password: {$password} (shown once, change it after the first login)" : ''));
    }
}
