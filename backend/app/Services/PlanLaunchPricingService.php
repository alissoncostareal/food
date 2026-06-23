<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Store;
use Illuminate\Support\Carbon;

class PlanLaunchPricingService
{
    public function launchSlotsUsed(Plan $plan): int
    {
        if (! $this->hasLaunchConfiguration($plan)) {
            return 0;
        }

        return Store::query()
            ->where('plan_id', $plan->id)
            ->whereNotNull('subscription_locked_price')
            ->whereIn('subscription_status', ['active', 'past_due'])
            ->whereNotNull('pagarme_subscription_id')
            ->count();
    }

    public function launchSlotsRemaining(Plan $plan): ?int
    {
        if (! $this->hasLaunchConfiguration($plan)) {
            return null;
        }

        return max(0, (int) $plan->launch_slots - $this->launchSlotsUsed($plan));
    }

    public function hasLaunchOfferAvailable(Plan $plan): bool
    {
        $remaining = $this->launchSlotsRemaining($plan);

        return $remaining !== null && $remaining > 0;
    }

    public function priceForNewSubscription(Plan $plan): float
    {
        if ($this->hasLaunchOfferAvailable($plan)) {
            return (float) $plan->launch_price;
        }

        return (float) $plan->price;
    }

    public function lockedPriceUntil(Plan $plan): ?Carbon
    {
        if (! $this->hasLaunchConfiguration($plan)) {
            return null;
        }

        $months = max(1, (int) ($plan->launch_price_months ?? 12));

        return now()->addMonths($months);
    }

    public function shouldApplyLaunchPricing(Plan $plan, float $chargedPrice): bool
    {
        if (! $this->hasLaunchConfiguration($plan)) {
            return false;
        }

        return abs($chargedPrice - (float) $plan->launch_price) < 0.01;
    }

    public function planPresentation(Plan $plan): array
    {
        $regularPrice = (float) $plan->price;
        $launchPrice = $plan->launch_price !== null ? (float) $plan->launch_price : null;
        $remaining = $this->launchSlotsRemaining($plan);
        $offerAvailable = $this->hasLaunchOfferAvailable($plan);
        $displayPrice = $offerAvailable && $launchPrice !== null ? $launchPrice : $regularPrice;

        return [
            'regular_price' => $regularPrice,
            'launch_price' => $launchPrice,
            'launch_slots' => $plan->launch_slots !== null ? (int) $plan->launch_slots : null,
            'launch_slots_used' => $this->launchSlotsUsed($plan),
            'launch_slots_remaining' => $remaining,
            'launch_offer_available' => $offerAvailable,
            'launch_price_months' => (int) ($plan->launch_price_months ?? 12),
            'display_price' => $displayPrice,
        ];
    }

    private function hasLaunchConfiguration(Plan $plan): bool
    {
        return $plan->launch_price !== null
            && (float) $plan->launch_price > 0
            && (int) $plan->launch_slots > 0;
    }
}
