<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\DemoDashboardSeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SuperAdminDemoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveProduct(Store $store, array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'X-Burger Demo';

        return Product::query()->create(array_merge([
            'store_id' => $store->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => 'Produto para testes',
            'price' => 24.90,
            'is_active' => true,
        ], $overrides));
    }

    private function superAdmin(string $password = 'secret-password'): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password' => bcrypt($password),
        ]);
    }

    #[Test]
    public function guest_cannot_seed_demo_dashboard(): void
    {
        $store = Store::factory()->create();

        $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
        ])->assertUnauthorized();
    }

    #[Test]
    public function merchant_cannot_seed_demo_dashboard(): void
    {
        $store = Store::factory()->create();

        Sanctum::actingAs(User::factory()->create([
            'role' => User::ROLE_STORE_OWNER,
        ]));

        $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    #[Test]
    public function super_admin_cannot_seed_demo_dashboard_with_wrong_password(): void
    {
        $admin = $this->superAdmin();
        $store = Store::factory()->create();

        $this->createActiveProduct($store);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    #[Test]
    public function super_admin_cannot_seed_demo_dashboard_without_active_products(): void
    {
        $admin = $this->superAdmin();
        $store = Store::factory()->create();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store']);
    }

    #[Test]
    public function super_admin_can_seed_demo_dashboard_for_store(): void
    {
        $admin = $this->superAdmin();
        $store = Store::factory()->create(['slug' => 'lojademo']);

        $this->createActiveProduct($store, ['name' => 'X-Salada Demo', 'price' => 18.50]);
        $this->createActiveProduct($store, ['name' => 'Combo Demo', 'price' => 39.90]);
        $this->createActiveProduct($store, ['name' => 'Suco Demo', 'price' => 9.90]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
            'clear_existing' => true,
        ]);

        $response->assertOk();

        $created = (int) $response->json('result.created');
        $this->assertGreaterThan(0, $created);

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'order_source' => DemoDashboardSeedService::ORDER_SOURCE,
        ]);

        $demoOrders = $store->orders()
            ->where('order_source', DemoDashboardSeedService::ORDER_SOURCE)
            ->count();

        $this->assertGreaterThan(30, $demoOrders);
    }

    #[Test]
    public function seeding_demo_dashboard_replaces_previous_demo_orders_when_clear_existing_is_true(): void
    {
        $admin = $this->superAdmin();
        $store = Store::factory()->create();

        $this->createActiveProduct($store);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
            'clear_existing' => true,
        ])->assertOk();

        $firstIds = $store->orders()
            ->where('order_source', DemoDashboardSeedService::ORDER_SOURCE)
            ->pluck('id')
            ->all();

        $secondResponse = $this->postJson("/api/v1/super-admin/stores/{$store->id}/demo-dashboard", [
            'password' => 'secret-password',
            'clear_existing' => true,
        ])->assertOk();

        foreach ($firstIds as $id) {
            $this->assertDatabaseMissing('orders', ['id' => $id]);
        }

        $this->assertSame(
            (int) $secondResponse->json('result.created'),
            $store->orders()
                ->where('order_source', DemoDashboardSeedService::ORDER_SOURCE)
                ->count()
        );
    }
}
