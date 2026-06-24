<?php

namespace Tests\Unit;

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
    }
}
