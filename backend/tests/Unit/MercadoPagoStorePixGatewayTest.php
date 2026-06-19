<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\Payments\Gateways\MercadoPagoStorePixGateway;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class MercadoPagoStorePixGatewayTest extends TestCase
{
    #[Test]
    public function it_rejects_local_domain_emails_for_production_tokens(): void
    {
        $gateway = new MercadoPagoStorePixGateway;
        $method = new ReflectionMethod($gateway, 'buildPayerEmail');

        $order = new Order([
            'customer_phone' => '5585999999999',
        ]);
        $order->id = 42;
        $order->setRelation('user', new User([
            'email' => 'cliente_5585999999999@checkout.local',
        ]));

        $email = $method->invoke($gateway, $order, 'APP_USR-production-token');

        $this->assertSame('pedido+42.5585999999999@customers.partiumenu.com.br', $email);
    }

    #[Test]
    public function it_uses_testuser_email_for_test_tokens(): void
    {
        $gateway = new MercadoPagoStorePixGateway;
        $method = new ReflectionMethod($gateway, 'buildPayerEmail');

        $order = new Order([
            'customer_phone' => '5585888888888',
        ]);
        $order->id = 7;
        $order->setRelation('user', new User([
            'email' => 'cliente_5585888888888@checkout.local',
        ]));

        $email = $method->invoke($gateway, $order, 'TEST-access-token');

        $this->assertSame('test_user_5585888888888@testuser.com', $email);
    }

    #[Test]
    public function it_falls_back_when_remote_expiration_is_in_the_past(): void
    {
        $gateway = new MercadoPagoStorePixGateway;
        $method = new ReflectionMethod($gateway, 'resolveExpiresAt');
        $fallback = now()->addMinutes(30);

        $resolved = $method->invoke($gateway, now()->subMinute()->toIso8601String(), $fallback);

        $this->assertTrue($resolved->greaterThan(now()->addMinutes(29)));
    }
}
