<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\PlanLaunchPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLaunchPricingTest extends TestCase
{
    use RefreshDatabase;

    private function premiumPlan(array $overrides = []): Plan
    {
        Plan::query()
            ->where('slug', '!=', 'premium')
            ->update(['is_visible' => false]);

        $plan = Plan::query()->where('slug', 'premium')->firstOrFail();

        $plan->update(array_merge([
            'price' => 200,
            'launch_price' => 69,
            'launch_slots' => 20,
            'launch_price_months' => 12,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));

        return $plan->fresh();
    }

    #[Test]
    public function plan_api_returns_launch_pricing_when_slots_are_available(): void
    {
        $plan = $this->premiumPlan();

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('0.id', $plan->id)
            ->assertJsonPath('0.price', 69)
            ->assertJsonPath('0.regular_price', 200)
            ->assertJsonPath('0.launch_offer_available', true)
            ->assertJsonPath('0.launch_slots_remaining', 20);
    }

    #[Test]
    public function plan_api_uses_regular_price_when_launch_slots_are_full(): void
    {
        $plan = $this->premiumPlan(['launch_slots' => 1]);
        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);

        Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'subscription_locked_price' => 69,
            'pagarme_subscription_id' => 'sub_test_1',
        ]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('0.price', 200)
            ->assertJsonPath('0.launch_offer_available', false)
            ->assertJsonPath('0.launch_slots_remaining', 0);
    }

    #[Test]
    public function launch_pricing_service_counts_only_active_locked_subscriptions(): void
    {
        $plan = $this->premiumPlan(['launch_slots' => 2]);
        $service = app(PlanLaunchPricingService::class);
        $owner = User::factory()->create(['role' => User::ROLE_STORE_OWNER]);

        Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'subscription_locked_price' => 69,
            'pagarme_subscription_id' => 'sub_test_1',
        ]);

        Store::factory()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'subscription_status' => 'canceled',
            'subscription_locked_price' => 69,
            'pagarme_subscription_id' => 'sub_test_2',
        ]);

        $this->assertSame(1, $service->launchSlotsUsed($plan));
        $this->assertTrue($service->hasLaunchOfferAvailable($plan));
        $this->assertSame(69.0, $service->priceForNewSubscription($plan));
    }
}
