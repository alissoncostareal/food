<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\WhatsappBotEngine;
use App\Services\WhatsappOrderUrlService;
use App\Services\WhatsappProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappBotEngineTest extends TestCase
{
    use RefreshDatabase;

    private function storeWithBot(): Store
    {
        $plan = Plan::query()->create([
            'name' => 'Pro',
            'slug' => 'pro-bot-test',
            'description' => 'Test',
            'price' => 99.90,
            'max_stores' => 1,
            'features' => ['whatsapp_auto' => true, 'whatsapp_bot' => true],
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);

        return Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'slug' => 'loja-bot-teste',
            'evolution_status' => WhatsappProvisioningService::STATUS_CONNECTED,
            'whatsapp_bot_enabled' => true,
        ]);
    }

    #[Test]
    public function it_finds_latest_order_when_whatsapp_and_checkout_phones_use_different_formats(): void
    {
        $store = $this->storeWithBot();

        Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '5585999999999',
            'address' => 'Rua Teste',
            'status' => 'preparing',
            'total_amount' => 40,
            'payment_method' => 'pix',
        ]);

        $session = WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
        ]);

        $reply = app(WhatsappBotEngine::class)->tryReply($store, $session, '3');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Em preparo', $reply);
        $this->assertStringNotContainsString('Não encontrei', $reply);
    }

    #[Test]
    public function it_finds_order_when_session_phone_has_country_code_and_order_has_masked_local_format(): void
    {
        $store = $this->storeWithBot();

        Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '(85) 99999-9999',
            'address' => 'Rua Teste',
            'status' => 'ready',
            'total_amount' => 40,
            'payment_method' => 'pix',
        ]);

        $session = WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
        ]);

        $reply = app(WhatsappBotEngine::class)->tryReply($store, $session, '3');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Pronto', $reply);
    }

    #[Test]
    public function it_finds_canceled_order_for_status_lookup(): void
    {
        $store = $this->storeWithBot();

        Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'canceled',
            'total_amount' => 40,
            'payment_method' => 'pix',
        ]);

        $session = WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
        ]);

        $reply = app(WhatsappBotEngine::class)->tryReply($store, $session, '3');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Cancelado', $reply);
    }

    #[Test]
    public function it_uses_custom_menu_digit_mapping(): void
    {
        $store = $this->storeWithBot();
        $store->update([
            'whatsapp_bot_menu' => [
                ['action' => 'menu', 'digit' => '1', 'label' => 'Cardápio', 'enabled' => true],
                ['action' => 'hours', 'digit' => '2', 'label' => 'Horário', 'enabled' => true],
                ['action' => 'order', 'digit' => '9', 'label' => 'Meu pedido', 'enabled' => true],
                ['action' => 'human', 'digit' => '4', 'label' => 'Atendente', 'enabled' => true],
            ],
        ]);

        Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '85999999999',
            'address' => 'Rua Teste',
            'status' => 'ready',
            'total_amount' => 40,
            'payment_method' => 'pix',
        ]);

        $session = WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
        ]);

        $reply = app(WhatsappBotEngine::class)->tryReply($store->fresh(), $session, '9');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Pronto', $reply);
    }

    #[Test]
    public function it_suppresses_reply_for_checkout_order_message(): void
    {
        $store = $this->storeWithBot();
        $store->update(['whatsapp_number' => '5585888888888']);

        Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '5585999999999',
            'address' => 'Rua Teste',
            'status' => 'pending',
            'total_amount' => 40,
            'payment_method' => 'pix',
        ]);

        $order = Order::query()->where('store_id', $store->id)->first();
        $url = app(WhatsappOrderUrlService::class)->buildForOrder($order);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $orderMessage = urldecode((string) ($query['text'] ?? ''));

        $engine = app(WhatsappBotEngine::class);

        $this->assertNotSame('', $orderMessage);
        $this->assertTrue($engine->shouldSuppressReply($orderMessage));

        $session = WhatsappSession::query()->create([
            'store_id' => $store->id,
            'customer_phone' => '5585999999999',
            'state' => WhatsappSession::STATE_IDLE,
        ]);

        $this->assertNull($engine->tryReply($store, $session, $orderMessage));
    }

    #[Test]
    public function it_includes_menu_when_custom_welcome_is_configured(): void
    {
        $store = $this->storeWithBot();
        $store->update(['whatsapp_bot_welcome' => 'Olá da nossa equipe!']);

        $welcome = app(WhatsappBotEngine::class)->welcomeMessage($store->fresh());

        $this->assertStringContainsString('Olá da nossa equipe!', $welcome);
        $this->assertStringContainsString('1 - Ver cardápio', $welcome);
        $this->assertStringContainsString('Você também pode escrever:', $welcome);
    }
}
