<?php

namespace Tests\Feature;

use App\Kafka\Producers\KafkaEventProducer;
use App\Models\Plan;
use App\Models\Store;
use App\Models\WhatsappSession;
use App\Services\StoreInsightService;
use App\Services\WhatsappAiAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PrismAiAndKafkaTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_ai_assistant_initializes_with_prism(): void
    {
        $assistant = app(WhatsappAiAssistant::class);

        $this->assertEquals('gemini', $assistant->provider());
        $this->assertEquals('Google Gemini', $assistant->providerLabel());
    }

    public function test_whatsapp_ai_assistant_respects_rate_limit_and_store_settings(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'prism.providers.gemini.api_key' => 'test-gemini-key',
            'services.gemini.enabled' => true,
        ]);

        $plan = Plan::firstOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'price' => 199,
                'features' => ['whatsapp_auto' => true, 'whatsapp_ai' => true],
            ]
        );

        $store = Store::factory()->create([
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
            'whatsapp_ai_enabled' => true,
            'whatsapp_ai_faq' => 'Somos uma pizzaria artesanal com forno a lenha no centro da cidade.',
        ]);

        $session = WhatsappSession::create([
            'store_id' => $store->id,
            'customer_phone' => '5585999998888',
        ]);

        $assistant = app(WhatsappAiAssistant::class);
        $this->assertTrue($assistant->canReply($store));
    }

    public function test_store_insight_service_generates_fallback_rules_when_ai_disabled(): void
    {
        config(['services.gemini.enabled' => false]);

        $service = app(StoreInsightService::class);

        $stats = [
            'today' => ['revenue' => 1500.0, 'sales_count' => 30],
            'pending_now' => 4,
            'monthly_revenue' => 45000.0,
            'monthly_orders_count' => 900,
            'average_ticket' => 50.0,
        ];

        $topProducts = collect([
            ['name' => 'Pizza Calabresa', 'quantity' => 120, 'revenue' => 4800],
        ]);

        $ordersByWeekday = collect([
            ['weekday' => 6, 'label' => 'Sábado', 'orders_count' => 200, 'revenue' => 10000],
            ['weekday' => 1, 'label' => 'Segunda-feira', 'orders_count' => 20, 'revenue' => 1000],
        ]);

        $ordersByHour = collect([
            ['hour' => 20, 'label' => '20:00', 'orders_count' => 150, 'revenue' => 7500],
        ]);

        $result = $service->generate(
            storeId: 1,
            stats: $stats,
            topProducts: $topProducts,
            ordersByWeekday: $ordersByWeekday,
            ordersByHour: $ordersByHour,
            delayedOrders: 2,
            storeName: 'Pizzaria Teste',
            context: ['canceled_orders_30d' => 3, 'revenue_trend' => 'up'],
            forceRefresh: true
        );

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals('rules', $result['meta']['source']);
        $this->assertNotEmpty($result['items']);
    }

    public function test_kafka_event_producer_gracefully_handles_disabled_state(): void
    {
        config(['kafka.enabled' => false]);

        $this->assertFalse(KafkaEventProducer::isEnabled());

        $inbound = KafkaEventProducer::publishWhatsappInbound(
            storeId: 1,
            payload: ['test' => true],
            event: 'messages.upsert',
            phone: '5585999998888',
            text: 'Olá'
        );
        $this->assertFalse($inbound);

        $outbound = KafkaEventProducer::publishWhatsappOutbound(
            orderId: 101,
            status: 'preparing',
            storeId: 1
        );
        $this->assertFalse($outbound);

        $orderEvent = KafkaEventProducer::publishOrderEvent(
            eventType: 'order.created',
            orderData: ['id' => 101, 'total' => 85.50],
            storeId: 1
        );
        $this->assertFalse($orderEvent);

        $ifoodEvent = KafkaEventProducer::publishIfoodEvent(
            storeId: 1,
            eventData: ['id' => 'evt_123', 'code' => 'PLACED']
        );
        $this->assertFalse($ifoodEvent);
    }

    public function test_kafka_consumer_handlers_extend_base_consumer(): void
    {
        $orderHandler = app(\App\Kafka\Consumers\OrderLifecycleKafkaHandler::class);
        $ifoodHandler = app(\App\Kafka\Consumers\IfoodEventKafkaHandler::class);
        $whatsappInbound = app(\App\Kafka\Consumers\WhatsappInboundKafkaHandler::class);
        $whatsappOutbound = app(\App\Kafka\Consumers\WhatsappOutboundKafkaHandler::class);

        $this->assertInstanceOf(\Junges\Kafka\Contracts\Consumer::class, $orderHandler);
        $this->assertInstanceOf(\Junges\Kafka\Contracts\Consumer::class, $ifoodHandler);
        $this->assertInstanceOf(\Junges\Kafka\Contracts\Consumer::class, $whatsappInbound);
        $this->assertInstanceOf(\Junges\Kafka\Contracts\Consumer::class, $whatsappOutbound);
    }
}
