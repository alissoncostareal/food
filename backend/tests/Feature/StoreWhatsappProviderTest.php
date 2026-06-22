<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\MetaWhatsappProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreWhatsappProviderTest extends TestCase
{
    use RefreshDatabase;

    private function merchantWithStore(): array
    {
        $plan = Plan::query()->create([
            'name' => 'Pro',
            'slug' => 'pro-provider',
            'description' => 'Test',
            'price' => 99.90,
            'max_stores' => 1,
            'features' => ['whatsapp_auto' => true],
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);
        $store = Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'slug' => 'loja-provider',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        Sanctum::actingAs($owner);
        $owner->forceFill(['current_store_id' => $store->id])->save();

        return [$owner, $store];
    }

    #[Test]
    public function merchant_can_switch_whatsapp_provider(): void
    {
        [, $store] = $this->merchantWithStore();

        $this->putJson('/api/v1/merchant/integrations/whatsapp/provider', [
            'provider' => 'meta',
        ])
            ->assertOk()
            ->assertJsonPath('whatsapp.provider', 'meta');

        $this->assertSame('meta', $store->fresh()->whatsapp_provider);
    }

    #[Test]
    public function merchant_can_complete_meta_signup_in_test_mode(): void
    {
        config([
            'services.meta_whatsapp.enabled' => true,
            'services.meta_whatsapp.test_mode' => true,
            'services.meta_whatsapp.app_id' => 'app-id',
            'services.meta_whatsapp.app_secret' => 'secret',
            'services.meta_whatsapp.embedded_signup_config_id' => 'config-id',
        ]);

        [, $store] = $this->merchantWithStore();

        $this->postJson('/api/v1/merchant/integrations/whatsapp/meta/complete-signup', [
            'waba_id' => 'waba-test',
            'phone_number_id' => 'phone-test',
            'display_phone' => '+55 85 98910-2317',
        ])
            ->assertOk()
            ->assertJsonPath('whatsapp.status', MetaWhatsappProvisioningService::STATUS_CONNECTED);

        $store->refresh();
        $this->assertSame(Store::WHATSAPP_PROVIDER_META, $store->whatsapp_provider);
        $this->assertSame('phone-test', $store->meta_phone_number_id);
    }

    #[Test]
    public function meta_webhook_records_customer_inbound_for_store(): void
    {
        [, $store] = $this->merchantWithStore();
        $store->update([
            'whatsapp_provider' => Store::WHATSAPP_PROVIDER_META,
            'meta_phone_number_id' => 'phone-webhook-1',
            'meta_whatsapp_status' => MetaWhatsappProvisioningService::STATUS_CONNECTED,
        ]);

        config(['services.meta_whatsapp.webhook_verify_token' => 'verify-token']);

        $this->postJson('/api/v1/webhooks/meta/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-webhook-1'],
                        'messages' => [[
                            'from' => '5585999999999',
                            'type' => 'text',
                            'text' => ['body' => 'Novo pedido #10'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
        ]);
    }
}
