<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\WhatsappBotMessageTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappBotMessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_custom_menu_reply_from_store_settings(): void
    {
        $store = Store::factory()->create([
            'slug' => 'bot-loja',
            'whatsapp_bot_messages' => [
                'reply_menu' => 'Peça aqui: {menu_url}',
            ],
        ]);

        $message = WhatsappBotMessageTemplates::renderMenuReply($store);

        $this->assertStringContainsString('Peça aqui:', $message);
        $this->assertStringContainsString($store->menuUrl(), $message);
    }

    #[Test]
    public function it_builds_welcome_from_custom_menu_options(): void
    {
        $store = Store::factory()->create([
            'whatsapp_bot_menu' => [
                ['action' => 'menu', 'digit' => '7', 'label' => 'Ver nosso cardápio', 'enabled' => true],
                ['action' => 'hours', 'digit' => '2', 'label' => 'Horário', 'enabled' => true],
                ['action' => 'order', 'digit' => '3', 'label' => 'Pedido', 'enabled' => true],
                ['action' => 'human', 'digit' => '4', 'label' => 'Atendente', 'enabled' => true],
            ],
        ]);

        $welcome = WhatsappBotMessageTemplates::renderWelcome($store);

        $this->assertStringContainsString('7 - Ver nosso cardápio', $welcome);
        $this->assertStringContainsString('Você também pode escrever:', $welcome);
    }

    #[Test]
    public function it_keeps_menu_and_keyword_hints_when_custom_welcome_is_set(): void
    {
        $store = Store::factory()->create([
            'whatsapp_bot_welcome' => 'Seja bem-vindo à {loja}!',
            'whatsapp_bot_menu' => [
                ['action' => 'menu', 'digit' => '1', 'label' => 'Cardápio', 'enabled' => true],
                ['action' => 'hours', 'digit' => '2', 'label' => 'Horário', 'enabled' => true],
                ['action' => 'order', 'digit' => '3', 'label' => 'Pedido', 'enabled' => true],
                ['action' => 'human', 'digit' => '4', 'label' => 'Atendente', 'enabled' => true],
            ],
        ]);

        $welcome = WhatsappBotMessageTemplates::renderWelcome($store);

        $this->assertStringContainsString('Seja bem-vindo à '.$store->name.'!', $welcome);
        $this->assertStringContainsString('1 - Cardápio', $welcome);
        $this->assertStringContainsString('*cardápio*', $welcome);
        $this->assertStringContainsString('*pedido*', $welcome);
    }

    #[Test]
    public function it_includes_order_items_in_status_reply(): void
    {
        $store = Store::factory()->create(['slug' => 'bot-loja-detalhes']);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'name' => 'X-Burger',
            'slug' => 'x-burger',
            'description' => 'Teste',
            'price' => 13.95,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente',
            'customer_phone' => '85999999999',
            'address' => 'Rua das Flores, 100 - Centro',
            'district' => 'Centro',
            'status' => 'preparing',
            'fulfillment_type' => 'delivery',
            'total_amount' => 32.90,
            'delivery_fee' => 5,
            'payment_method' => 'pix',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 13.95,
            'subtotal' => 27.90,
        ]);

        $message = WhatsappBotMessageTemplates::renderOrderReply($store, $order->fresh(['items.product']));

        $this->assertStringContainsString('2x X-Burger', $message);
        $this->assertStringContainsString('R$ 32,90', $message);
        $this->assertStringContainsString('Em preparo', $message);
        $this->assertStringContainsString('Entrega', $message);
    }
}
