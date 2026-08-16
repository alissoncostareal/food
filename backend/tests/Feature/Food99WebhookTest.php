<?php

namespace Tests\Feature;

use App\Models\Food99WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Food99WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_health_check_returns_success(): void
    {
        $this->getJson('/api/v1/integrations/food99/webhook')
            ->assertOk()
            ->assertJsonPath('errno', 0)
            ->assertJsonPath('msg', 'success');
    }

    public function test_post_persists_event_and_acks(): void
    {
        $payload = [
            'id' => 'evt-99-1',
            'type' => 'order.create',
            'shopId' => 'shop-abc',
            'orderId' => 'ord-123',
            'data' => ['status' => 'created'],
        ];

        $this->postJson('/api/v1/integrations/food99/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('errno', 0)
            ->assertJsonPath('received', true);

        $this->assertDatabaseHas('food99_webhook_events', [
            'event_id' => 'evt-99-1',
            'event_type' => 'order.create',
            'shop_id' => 'shop-abc',
            'order_id' => 'ord-123',
            'status' => 'received',
        ]);

        $this->assertSame(1, Food99WebhookEvent::query()->count());
    }

    public function test_invalid_signature_is_rejected_when_secret_is_set(): void
    {
        config(['services.food99.webhook_secret' => 'test-secret']);

        $this->postJson('/api/v1/integrations/food99/webhook', [
            'type' => 'order.create',
        ], [
            'X-99Food-Signature' => 'invalid',
        ])->assertUnauthorized()
            ->assertJsonPath('errno', 401);
    }
}
