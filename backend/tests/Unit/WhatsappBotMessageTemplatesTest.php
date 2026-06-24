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
    public function it_builds_welcome_from_custom_option_labels(): void
    {
        $store = Store::factory()->create([
            'whatsapp_bot_messages' => [
                'option_menu' => '1 - Ver nosso cardápio',
            ],
        ]);

        $welcome = WhatsappBotMessageTemplates::renderWelcome($store);

        $this->assertStringContainsString('1 - Ver nosso cardápio', $welcome);
    }
}
