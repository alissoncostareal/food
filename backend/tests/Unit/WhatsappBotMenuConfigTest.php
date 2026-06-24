<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\WhatsappBotMenuConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappBotMenuConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_legacy_option_labels_into_menu_config(): void
    {
        $store = Store::factory()->create([
            'whatsapp_bot_messages' => [
                'option_menu' => '9 - Cardápio especial',
            ],
        ]);

        $options = WhatsappBotMenuConfig::forStore($store);
        $menu = collect($options)->firstWhere('action', WhatsappBotMenuConfig::ACTION_MENU);

        $this->assertSame('9', $menu['digit']);
        $this->assertSame('Cardápio especial', $menu['label']);
    }

    #[Test]
    public function it_rejects_duplicate_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WhatsappBotMenuConfig::sanitizeInput([
            ['action' => 'menu', 'digit' => '1', 'label' => 'Cardápio', 'enabled' => true],
            ['action' => 'hours', 'digit' => '1', 'label' => 'Horário', 'enabled' => true],
        ]);
    }

    #[Test]
    public function it_builds_menu_lines_from_store_config(): void
    {
        $store = Store::factory()->create([
            'whatsapp_bot_menu' => [
                ['action' => 'menu', 'digit' => '5', 'label' => 'Ver cardápio', 'enabled' => true],
                ['action' => 'hours', 'digit' => '2', 'label' => 'Horário', 'enabled' => true],
                ['action' => 'order', 'digit' => '3', 'label' => 'Pedido', 'enabled' => true],
                ['action' => 'human', 'digit' => '4', 'label' => 'Atendente', 'enabled' => true],
            ],
        ]);

        $this->assertSame(WhatsappBotMenuConfig::ACTION_MENU, WhatsappBotMenuConfig::actionForDigit($store, '5'));
        $this->assertContains('5 - Ver cardápio', WhatsappBotMenuConfig::menuLines($store));
    }
}
