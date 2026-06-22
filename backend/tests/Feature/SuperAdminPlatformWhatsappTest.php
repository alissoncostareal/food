<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformWhatsappService;
use App\Services\WhatsappProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SuperAdminPlatformWhatsappTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.enabled' => true,
            'services.evolution.base_url' => 'https://evolution.test',
            'services.evolution.api_key' => 'test-key',
            'services.evolution.default_instance' => 'partiumenu-otp',
            'services.evolution.test_mode' => true,
        ]);

        Cache::flush();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function guest_cannot_access_platform_whatsapp_connection(): void
    {
        $this->getJson('/api/v1/super-admin/whatsapp/connection')
            ->assertUnauthorized();
    }

    #[Test]
    public function merchant_cannot_access_platform_whatsapp_connection(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => User::ROLE_STORE_OWNER,
        ]));

        $this->getJson('/api/v1/super-admin/whatsapp/connection')
            ->assertForbidden();
    }

    #[Test]
    public function super_admin_can_view_platform_whatsapp_connection(): void
    {
        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances*' => Http::response([]),
            'https://evolution.test/instance/connectionState/partiumenu-otp' => Http::response([
                'instance' => ['state' => 'close'],
            ]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super-admin/whatsapp/connection')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonPath('instance_name', 'partiumenu-otp')
            ->assertJsonPath('purpose', 'otp')
            ->assertJsonPath('status', WhatsappProvisioningService::STATUS_PENDING);
    }

    #[Test]
    public function super_admin_can_provision_platform_whatsapp_in_test_mode(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/provision')
            ->assertOk()
            ->assertJsonPath('whatsapp.status', WhatsappProvisioningService::STATUS_CONNECTED)
            ->assertJsonPath('whatsapp.instance_name', 'partiumenu-otp');

        $this->assertSame(
            WhatsappProvisioningService::STATUS_CONNECTED,
            PlatformSetting::get('platform_whatsapp_status')
        );
    }

    #[Test]
    public function super_admin_can_provision_platform_whatsapp_against_evolution_api(): void
    {
        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances*' => Http::response([[
                'instanceName' => 'partiumenu-otp',
                'owner' => '5585999999999@s.whatsapp.net',
                'connectionStatus' => 'open',
            ]]),
            'https://evolution.test/instance/create' => Http::response(['instance' => ['instanceName' => 'partiumenu-otp']]),
            'https://evolution.test/instance/connectionState/partiumenu-otp' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/provision')
            ->assertOk()
            ->assertJsonPath('whatsapp.status', WhatsappProvisioningService::STATUS_CONNECTED)
            ->assertJsonPath('whatsapp.whatsapp_number', '5585999999999');
    }

    #[Test]
    public function super_admin_can_disconnect_and_prepare_number_change(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585999999999');
        PlatformSetting::set('platform_whatsapp_connected_at', now()->toIso8601String());

        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/instance/logout/partiumenu-otp' => Http::response(['status' => 'SUCCESS']),
            'https://evolution.test/instance/connect/partiumenu-otp' => Http::response([
                'base64' => 'abc123',
                'pairingCode' => 'ABCD-EFGH',
            ]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/disconnect')
            ->assertOk()
            ->assertJsonPath('whatsapp.status', WhatsappProvisioningService::STATUS_AWAITING_QR)
            ->assertJsonPath('whatsapp.qrcode.pairing_code', 'ABCD-EFGH');

        $this->assertSame(
            WhatsappProvisioningService::STATUS_AWAITING_QR,
            PlatformSetting::get('platform_whatsapp_status')
        );
        $this->assertSame('', PlatformSetting::get('platform_whatsapp_number'));
    }

    #[Test]
    public function super_admin_can_send_test_message_when_connected(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585988887777');

        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances*' => Http::response([[
                'instanceName' => 'partiumenu-otp',
                'owner' => '5585988887777@s.whatsapp.net',
                'connectionStatus' => 'open',
            ]]),
            'https://evolution.test/instance/connectionState/partiumenu-otp' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
            'https://evolution.test/message/sendText/partiumenu-otp' => Http::response(['key' => 'msg-1']),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/test-message', [
            'phone' => '85977776666',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Mensagem de teste enviada.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/message/sendText/partiumenu-otp'));
    }

    #[Test]
    public function super_admin_cannot_send_test_message_to_connected_chip_number(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585989102317');

        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances*' => Http::response([[
                'instanceName' => 'partiumenu-otp',
                'owner' => '5585989102317@s.whatsapp.net',
                'connectionStatus' => 'open',
            ]]),
            'https://evolution.test/instance/connectionState/partiumenu-otp' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/test-message', [
            'phone' => '85989102317',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Envie o teste para outro número WhatsApp, não para o chip conectado.');
    }

    #[Test]
    public function super_admin_can_save_connected_chip_number_manually(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super-admin/whatsapp/number', [
            'phone' => '85989102317',
        ])
            ->assertOk()
            ->assertJsonPath('whatsapp.whatsapp_number', '5585989102317')
            ->assertJsonPath('whatsapp.whatsapp_number_display', '(85) 98910-2317');
    }

    #[Test]
    public function otp_send_fails_when_platform_whatsapp_is_not_connected(): void
    {
        config(['services.evolution.test_mode' => false]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '5585988887777',
            'email' => 'cliente_5585988887777@checkout.local',
        ]);

        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_AWAITING_QR);

        $this->postJson('/api/v1/customers/send-code', [
            'phone' => '85988887777',
        ])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Falha ao enviar o WhatsApp.');
    }

    #[Test]
    public function otp_send_uses_meta_authentication_template_when_configured(): void
    {
        config([
            'services.evolution.test_mode' => false,
            'services.meta_whatsapp.enabled' => true,
            'services.meta_whatsapp.test_mode' => false,
            'services.meta_whatsapp.app_id' => 'app-id',
            'services.meta_whatsapp.app_secret' => 'secret',
            'services.meta_whatsapp.embedded_signup_config_id' => 'config-id',
            'services.meta_whatsapp.otp_template_name' => 'partiumenu_otp',
        ]);

        PlatformSetting::set('platform_whatsapp_provider', PlatformWhatsappService::PROVIDER_META);
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_meta_phone_number_id', 'phone-otp-1');
        PlatformSetting::set('platform_meta_access_token', Crypt::encryptString('token-otp'));

        User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '5585988887777',
            'email' => 'cliente_5585988887777@checkout.local',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.otp']]]),
        ]);

        $this->postJson('/api/v1/customers/send-code', [
            'phone' => '85988887777',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Código enviado via WhatsApp.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/phone-otp-1/messages')
            && str_contains($request->body(), 'partiumenu_otp'));
    }
}
