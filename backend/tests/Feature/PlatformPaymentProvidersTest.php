<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\PlatformPaymentProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformPaymentProvidersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function super_admin_can_update_enabled_payment_providers(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/super-admin/settings', [
            'payment_grace_days' => 7,
            'extra_branch_monthly_price' => 49.9,
            'payment_providers_enabled' => ['pagarme'],
        ])
            ->assertOk()
            ->assertJsonPath('payment_providers.0.key', 'pagarme')
            ->assertJsonPath('payment_providers.0.enabled', true)
            ->assertJsonPath('payment_providers.1.key', 'mercadopago')
            ->assertJsonPath('payment_providers.1.enabled', false);

        $this->assertSame(['pagarme'], PlatformPaymentProviders::enabledKeys());
    }

    #[Test]
    public function merchant_catalog_hides_disabled_providers_except_for_demo_store(): void
    {
        PlatformPaymentProviders::saveEnabled(['pagarme']);

        $regularOwner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);
        Store::factory()->create([
            'user_id' => $regularOwner->id,
            'slug' => 'restaurante-real',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(7),
        ]);

        $demoOwner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);
        Store::factory()->create([
            'user_id' => $demoOwner->id,
            'slug' => 'lojademo',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($regularOwner);
        $this->getJson('/api/v1/merchant/payments/connection')
            ->assertOk()
            ->assertJsonPath('platform_payment_bypass', false)
            ->assertJsonCount(1, 'providers_catalog');

        Sanctum::actingAs($demoOwner);
        $this->getJson('/api/v1/merchant/payments/connection')
            ->assertOk()
            ->assertJsonPath('platform_payment_bypass', true)
            ->assertJsonCount(3, 'providers_catalog');
    }

    #[Test]
    public function merchant_cannot_connect_disabled_provider_on_regular_store(): void
    {
        PlatformPaymentProviders::saveEnabled(['pagarme']);

        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);
        Store::factory()->create([
            'user_id' => $owner->id,
            'slug' => 'restaurante-real',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/merchant/payments/providers/asaas', [
            'connection_method' => 'api_key',
            'credentials' => [
                'api_key' => 'test-key',
                'environment' => 'sandbox',
            ],
        ])->assertForbidden();
    }
}
