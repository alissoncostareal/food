<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\OrderWhatsappNotifier;
use App\Services\WhatsappProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderWhatsappNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.enabled' => true,
            'services.evolution.base_url' => 'https://evolution.test',
            'services.evolution.api_key' => 'test-key',
            'services.evolution.test_mode' => false,
        ]);
    }

    private function storeWithWhatsapp(): Store
    {
        $plan = Plan::query()->create([
            'name' => 'Pro',
            'slug' => 'pro-test',
            'description' => 'Test',
            'price' => 99.90,
            'max_stores' => 1,
            'features' => ['whatsapp_auto' => true],
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);

        return Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'slug' => 'loja-teste',
            'evolution_instance_name' => 'loja-teste',
            'evolution_status' => WhatsappProvisioningService::STATUS_CONNECTED,
            'whatsapp_provider' => Store::WHATSAPP_PROVIDER_EVOLUTION,
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);
    }

    private function recordCustomerInbound(Store $store, Order $order): void
    {
        WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
            'last_inbound_at' => now(),
        ]);
    }

    #[Test]
    public function it_does_not_notify_without_whatsapp_feature(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter-test',
            'description' => 'Test',
            'price' => 49.90,
            'max_stores' => 1,
            'features' => ['whatsapp_auto' => false],
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);
        $store = Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'evolution_status' => WhatsappProvisioningService::STATUS_CONNECTED,
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 50,
            'payment_method' => 'pix',
        ]);

        $notifier = app(OrderWhatsappNotifier::class);

        $this->assertFalse($notifier->canNotify($store, $order));
        $this->assertFalse($notifier->sendStatusUpdate($order, 'preparing'));
    }

    #[Test]
    public function it_notifies_web_checkout_without_prior_whatsapp_inbound(): void
    {
        $store = $this->storeWithWhatsapp();

        Http::fake([
            'https://evolution.test/message/sendText/loja-teste' => Http::response(['key' => 'msg-1']),
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'order_source' => 'web',
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 50,
            'payment_method' => 'pix',
        ]);

        $notifier = app(OrderWhatsappNotifier::class);

        $this->assertTrue($notifier->canNotify($store, $order));
        $this->assertTrue($notifier->sendStatusUpdate($order, 'pending'));
    }

    #[Test]
    public function it_does_not_notify_ifood_orders_without_customer_inbound_message(): void
    {
        $store = $this->storeWithWhatsapp();

        $order = Order::query()->create([
            'store_id' => $store->id,
            'order_source' => 'ifood',
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 50,
            'payment_method' => 'pix',
        ]);

        $notifier = app(OrderWhatsappNotifier::class);

        $this->assertFalse($notifier->canNotify($store, $order));
        $this->assertFalse($notifier->sendStatusUpdate($order, 'preparing'));
    }

    #[Test]
    public function it_sends_store_status_notification_through_store_instance(): void
    {
        $store = $this->storeWithWhatsapp();

        Http::fake([
            'https://evolution.test/message/sendText/loja-teste' => Http::response(['key' => 'msg-1']),
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 50,
            'payment_method' => 'pix',
        ]);

        $this->recordCustomerInbound($store, $order);

        $notifier = app(OrderWhatsappNotifier::class);

        $this->assertTrue($notifier->canNotify($store, $order));
        $this->assertTrue($notifier->sendStatusUpdate($order, 'preparing'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/message/sendText/loja-teste'));

        $order->refresh();
        $this->assertNotNull($order->sent_to_whatsapp_at);
    }

    #[Test]
    public function it_sends_store_status_notification_through_meta_provider(): void
    {
        config([
            'services.meta_whatsapp.enabled' => true,
            'services.meta_whatsapp.app_id' => 'app-id',
            'services.meta_whatsapp.app_secret' => 'secret',
            'services.meta_whatsapp.embedded_signup_config_id' => 'config-id',
            'services.meta_whatsapp.test_mode' => false,
        ]);

        $store = $this->storeWithWhatsapp();
        $store->update([
            'whatsapp_provider' => Store::WHATSAPP_PROVIDER_META,
            'meta_waba_id' => 'waba-1',
            'meta_phone_number_id' => 'phone-id-1',
            'meta_access_token' => 'token-1',
            'meta_whatsapp_status' => 'connected',
            'meta_connected_at' => now(),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 50,
            'payment_method' => 'pix',
        ]);

        $this->recordCustomerInbound($store, $order);

        $notifier = app(OrderWhatsappNotifier::class);

        $this->assertTrue($notifier->canNotify($store->fresh(), $order));
        $this->assertTrue($notifier->sendStatusUpdate($order, 'preparing'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/phone-id-1/messages'));
    }
}
