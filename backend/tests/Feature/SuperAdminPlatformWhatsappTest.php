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
            'services.meta_whatsapp.enabled' => true,
            'services.meta_whatsapp.test_mode' => false,
            'services.meta_whatsapp.app_id' => 'app-id',
            'services.meta_whatsapp.app_secret' => 'secret',
            'services.meta_whatsapp.embedded_signup_config_id' => 'config-id',
            'services.meta_whatsapp.otp_template_name' => 'partiumenu_otp',
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
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super-admin/whatsapp/connection')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonPath('provider', PlatformWhatsappService::PROVIDER_META)
            ->assertJsonPath('purpose', 'otp')
            ->assertJsonPath('status', WhatsappProvisioningService::STATUS_PENDING);
    }

    #[Test]
    public function super_admin_cannot_provision_evolution_for_platform_otp(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/provision')
            ->assertStatus(422)
            ->assertJsonPath('message', 'OTP da plataforma usa apenas WhatsApp oficial (Meta). Conecte pela Meta.');
    }

    #[Test]
    public function super_admin_can_disconnect_platform_meta_whatsapp(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585999999999');
        PlatformSetting::set('platform_meta_phone_number_id', 'phone-1');
        PlatformSetting::set('platform_meta_access_token', Crypt::encryptString('token'));

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/disconnect')
            ->assertOk()
            ->assertJsonPath('whatsapp.status', WhatsappProvisioningService::STATUS_PENDING)
            ->assertJsonPath('whatsapp.provider', PlatformWhatsappService::PROVIDER_META);
    }

    #[Test]
    public function super_admin_can_send_test_message_when_meta_connected(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585988887777');
        PlatformSetting::set('platform_meta_phone_number_id', 'phone-otp-1');
        PlatformSetting::set('platform_meta_access_token', Crypt::encryptString('token-otp'));

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/test-message', [
            'phone' => '85977776666',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Mensagem de teste enviada.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/phone-otp-1/messages'));
    }

    #[Test]
    public function super_admin_cannot_send_test_message_to_connected_chip_number(): void
    {
        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set('platform_whatsapp_number', '5585989102317');
        PlatformSetting::set('platform_meta_phone_number_id', 'phone-otp-1');
        PlatformSetting::set('platform_meta_access_token', Crypt::encryptString('token-otp'));

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
            ->assertJsonPath('whatsapp.whatsapp_number', '5585989102317');
    }

    #[Test]
    public function otp_send_fails_when_platform_whatsapp_is_not_connected(): void
    {
        config(['services.evolution.test_mode' => false]);

        User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '5585988887777',
            'email' => 'cliente_5585988887777@checkout.local',
        ]);

        PlatformSetting::set('platform_whatsapp_status', WhatsappProvisioningService::STATUS_PENDING);

        $this->postJson('/api/v1/customers/send-code', [
            'phone' => '85988887777',
        ])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Falha ao enviar o WhatsApp.');
    }

    #[Test]
    public function otp_send_uses_meta_authentication_template_when_configured(): void
    {
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

    #[Test]
    public function super_admin_cannot_switch_platform_provider_to_evolution(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super-admin/whatsapp/provider', [
            'provider' => 'evolution',
        ])
            ->assertStatus(422);
    }
}
