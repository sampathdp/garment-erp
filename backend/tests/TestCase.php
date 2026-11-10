<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Http\Middleware\EnsureSessionIsValid;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /** Origin configured in SANCTUM_STATEFUL_DOMAINS, so requests get session + CSRF handling. */
    protected const FRONTEND_ORIGIN = 'http://localhost:3000';

    public function fromFrontend(): static
    {
        return $this->withHeaders(['Origin' => self::FRONTEND_ORIGIN, 'Referer' => self::FRONTEND_ORIGIN.'/', 'Accept' => 'application/json']);
    }

    /** Unauthenticated SPA request, even after earlier signedInAs() calls in the same test. */
    public function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->fromFrontend();
    }

    /**
     * Authenticated SPA session for $user (as after a real login).
     */
    public function signedInAs(User $user): static
    {
        // Guards cache the resolved user between requests in one test; reset so each sign-in is honoured.
        $this->app['auth']->forgetGuards();

        return $this->fromFrontend()
            ->actingAs($user, 'web')
            ->withSession([EnsureSessionIsValid::AUTHENTICATED_AT => time(), EnsureSessionIsValid::SESSION_VERSION => $user->session_version ?? 1]);
    }

    /** Minimal order row for authorization tests (Orders actions arrive in Phase 04). */
    public function makeOrder(Branch $branch, Page $page, OrderStatus $status = OrderStatus::Confirmed): Order
    {
        $staff = User::factory()->create();
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'C'.Str::upper(Str::random(8)), 'name' => 'Test Customer', 'phone_e164' => '+94770000000',
            'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('orders')->insertGetId([
            'order_no' => 'JOB-'.Str::upper(Str::random(10)), 'branch_id' => $branch->id, 'page_id' => $page->id,
            'customer_id' => $customerId, 'sales_owner_id' => $staff->id, 'status' => $status->value,
            'payment_status' => 'unpaid', 'required_delivery_date' => now()->addDays(30)->toDateString(),
            'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    public function makeOrderItemId(Order $order): int
    {
        $chartId = DB::table('size_charts')->insertGetId(['name' => 'Chart '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $typeId = DB::table('product_types')->insertGetId(['code' => 'PT'.Str::upper(Str::random(6)), 'name' => 'Jersey', 'size_chart_id' => $chartId, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('order_items')->insertGetId([
            'order_id' => $order->id, 'product_type_id' => $typeId, 'quantity' => 10, 'unit_price_minor' => 100000,
            'line_total_minor' => 1000000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** User holding a seeded role (run PermissionSeeder first). */
    public function userWithRole(string $role, array $branches = [], array $pages = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->sync(array_map(fn (Branch $b) => $b->id, $branches));
        $user->pages()->sync(array_map(fn (Page $p) => $p->id, $pages));

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Branch>  $branches
     * @param  list<Page>  $pages
     */
    protected function userWith(array $permissions = [], array $branches = [], array $pages = []): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        $user->branches()->sync(array_map(fn (Branch $b) => $b->id, $branches));
        $user->pages()->sync(array_map(fn (Page $p) => $p->id, $pages));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
