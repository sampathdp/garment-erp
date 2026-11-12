<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use App\Modules\Pricing\Models\Material;
use App\Modules\Pricing\Models\Size;
use App\Modules\Pricing\Models\SizeChart;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Development data only. Refuses to run in production (run --class=PermissionSeeder there).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DatabaseSeeder is for development only. Run --class=PermissionSeeder in production.');

            return;
        }

        $this->call(PermissionSeeder::class);
        $this->call(CatalogSeeder::class);

        $org = Organization::query()->firstOrCreate(['name' => 'Demo Garments'], ['legal_name' => 'Demo Garments (Pvt) Ltd', 'default_currency' => 'LKR']);

        // ASSUMPTION (client question Q22 open): Monday to Saturday working, Sunday off.
        foreach (range(1, 7) as $day) {
            WorkingCalendarDay::query()->updateOrCreate(['organization_id' => $org->id, 'iso_weekday' => $day], ['is_working_day' => $day !== 7]);
        }

        $colombo = Branch::query()->firstOrCreate(['code' => 'CMB'], ['organization_id' => $org->id, 'name' => 'Colombo', 'is_active' => true]);
        Branch::query()->firstOrCreate(['code' => 'KDY'], ['organization_id' => $org->id, 'name' => 'Kandy', 'is_active' => true]);

        $pages = [
            ['name' => 'Jersey.lk', 'code' => 'JLK', 'invoice_prefix' => 'JLK', 'invoice_header_name' => 'Jersey.lk', 'platform' => 'website'],
            ['name' => 'Jersey Company', 'code' => 'JCO', 'invoice_prefix' => 'JCO', 'invoice_header_name' => 'Jersey Company', 'platform' => 'website'],
            ['name' => 'Neat Clothing', 'code' => 'NEAT', 'invoice_prefix' => 'NEAT', 'invoice_header_name' => 'Neat Clothing', 'platform' => 'website'],
            ['name' => 'T Shirts', 'code' => 'TSHIRT', 'invoice_prefix' => 'TS', 'invoice_header_name' => 'T Shirts', 'platform' => 'website'],
            ['name' => 'Ceylon Tshirts', 'code' => 'CTS', 'invoice_prefix' => 'CTS', 'invoice_header_name' => 'Ceylon Tshirts', 'platform' => 'website'],
        ];
        foreach ($pages as $p) {
            Page::query()->firstOrCreate(['code' => $p['code']], [
                'organization_id' => $org->id, 'name' => $p['name'], 'platform' => $p['platform'],
                'invoice_prefix' => $p['invoice_prefix'], 'invoice_header_name' => $p['invoice_header_name'], 'is_active' => true,
            ]);
        }

        $this->call(PriceListSeeder::class);

        // Development samples only (real sizes and fabrics are configured by the client).
        $chart = SizeChart::query()->firstOrCreate(['name' => SizeChart::STANDARD]);
        $standardSizes = [
            ['code' => '2XS', 'label' => '2XS', 'sort_order' => 10],
            ['code' => 'XS', 'label' => 'XS', 'sort_order' => 20],
            ['code' => 'S', 'label' => 'S', 'sort_order' => 30],
            ['code' => 'M', 'label' => 'M', 'sort_order' => 40],
            ['code' => 'L', 'label' => 'L', 'sort_order' => 50],
            ['code' => 'XL', 'label' => 'XL', 'sort_order' => 60],
            ['code' => '2XL', 'label' => '2XL', 'sort_order' => 70],
            ['code' => '3XL', 'label' => '3XL', 'sort_order' => 80],
            ['code' => '4XL', 'label' => '4XL', 'sort_order' => 90],
            ['code' => '5XL', 'label' => '5XL', 'sort_order' => 100],
            ['code' => '20', 'label' => '20 (Kids)', 'sort_order' => 110],
            ['code' => '22', 'label' => '22 (Kids)', 'sort_order' => 120],
            ['code' => '24', 'label' => '24 (Kids)', 'sort_order' => 130],
            ['code' => '26', 'label' => '26 (Kids)', 'sort_order' => 140],
            ['code' => '28', 'label' => '28 (Kids)', 'sort_order' => 150],
            ['code' => '30', 'label' => '30 (Kids)', 'sort_order' => 160],
            ['code' => '32', 'label' => '32 (Kids)', 'sort_order' => 170],
        ];
        foreach ($standardSizes as $s) {
            Size::query()->firstOrCreate(['size_chart_id' => $chart->id, 'code' => $s['code']], ['label' => $s['label'], 'sort_order' => $s['sort_order']]);
        }
        Material::query()->firstOrCreate(['code' => 'sample_fabric'], ['name' => 'Sample fabric', 'is_active' => true]);

        $email = (string) env('SEED_ADMIN_EMAIL', 'admin@example.com');
        $password = (string) env('SEED_ADMIN_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16);
        }

        if (! User::query()->where('email', $email)->exists()) {
            $admin = User::query()->create(['name' => 'System Owner', 'email' => $email, 'password' => $password]);
            $admin->assignRole(Roles::SUPER_ADMIN);
            $admin->branches()->syncWithoutDetaching([$colombo->id]);
            $this->command->info("Admin created: {$email}".($generated ? " / password: {$password} (shown once, change it)" : ''));
        }
    }
}
