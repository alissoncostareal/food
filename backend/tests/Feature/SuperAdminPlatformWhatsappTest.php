<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\WhatsappProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'https://evolution.test/instance/create' => Http::response(['instance' => ['instanceName' => 'partiumenu-otp']]),
            'https://evolution.test/instance/connectionState/partiumenu-otp' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
            'https://evolution.test/instance/fetchInstances*' => Http::sequence()
                ->push([])
                ->push([[
                    'instanceName' => 'partiumenu-otp',
                    'owner' => '5585999999999@s.whatsapp.net',
                ]]),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/provision')
            ->assertOk()
            ->assertJsonPath('whatsapp.status', WhatsappProvisioningService::STATUS_CONNECTED)
            ->assertJsonPath('whatsapp.whatsapp_number', '5585999999999');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/instance/create'));
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

        config(['services.evolution.test_mode' => false]);

        Http::fake([
            'https://evolution.test/message/sendText/partiumenu-otp' => Http::response(['key' => 'msg-1']),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/super-admin/whatsapp/test-message', [
            'phone' => '85999999999',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Mensagem de teste enviada.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/message/sendText/partiumenu-otp'));
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
}
