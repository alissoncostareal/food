<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Store) {
            $this->resource->ensureSubscriptionStateIsCurrent();
        }

        $productsUsage = null;

        if ($this->resource && method_exists($this->resource, 'maxProductsAllowed')) {
            $maxProducts = $this->maxProductsAllowed();
            $currentProducts = $this->products()->count();

            $productsUsage = [
                'current' => $currentProducts,
                'limit' => $maxProducts,
                'is_unlimited' => is_null($maxProducts),
                'reached' => ! is_null($maxProducts) && $currentProducts >= $maxProducts,
            ];
        }

        $storesUsage = null;

        if ($this->resource && method_exists($this->resource, 'isMatriz') && $this->isMatriz()) {
            $maxStores = $this->maxStoresAllowed();
            $currentStores = Store::query()
                ->where('user_id', $this->user_id)
                ->count();

            $storesUsage = [
                'current' => $currentStores,
                'limit' => $maxStores,
                'can_create_branch' => $currentStores < $maxStores,
            ];
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'store_type' => $this->store_type,
            'parent_store_id' => $this->parent_store_id,
            'name' => $this->name,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
            'is_open' => (bool) $this->is_open,
            'is_open_now' => $this->is_open_now,
            'opening_status' => $this->opening_status,
            'status_message' => $this->opening_status['message'] ?? null,
            'next_opening' => $this->opening_status['next_opening'] ?? null,
            'slug' => $this->slug,
            'instagram_link' => $this->instagram_link,
            'whatsapp_number' => $this->whatsapp_number,
            'whatsapp' => method_exists($this->resource, 'whatsappConnectionPayload')
                ? $this->whatsappConnectionPayload()
                : null,
            'business_hours' => $this->business_hours,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'delivery_fee' => $this->delivery_fee,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'payment_methods' => $this->acceptedPaymentMethods(),
            'accepted_payment_methods' => $this->acceptedPaymentMethods(),
            'online_payments_enabled' => (bool) $this->online_payments_enabled,
            'online_card_available' => method_exists($this->resource, 'onlineCardAvailable')
                ? $this->onlineCardAvailable()
                : false,
            'billing_email' => $this->billing_email,
            'user' => $this->whenLoaded('user', function () {
                if (!$this->user) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),

            'plan_id' => $this->plan_id,
            'plan_type' => $this->plan_type,
            'subscription_status' => $this->subscription_status,
            'subscription_ends_at' => $this->subscription_ends_at,
            'complimentary_until' => $this->complimentary_until,
            'complimentary_reason' => $this->complimentary_reason,
            'has_active_subscription' => method_exists($this->resource, 'hasActiveSubscription')
                ? $this->hasActiveSubscription()
                : false,
            'is_within_payment_grace' => method_exists($this->resource, 'isWithinPaymentGrace')
                ? $this->isWithinPaymentGrace()
                : false,
            'payment_grace_ends_at' => method_exists($this->resource, 'paymentGraceEndsAt')
                ? $this->paymentGraceEndsAt()?->toIso8601String()
                : null,
            'parent_store' => $this->when($this->parent_store_id, function () {
                if (! $this->relationLoaded('parentStore')) {
                    $this->load('parentStore:id,name,slug');
                }

                return $this->parentStore ? [
                    'id' => $this->parentStore->id,
                    'name' => $this->parentStore->name,
                    'slug' => $this->parentStore->slug,
                ] : null;
            }),

            'plan' => $this->whenLoaded('plan', function () {
                if (!$this->plan) {
                    return null;
                }

                return [
                    'id' => $this->plan->id,
                    'name' => $this->plan->name,
                    'slug' => $this->plan->slug,
                    'description' => $this->plan->description,
                    'price' => $this->plan->price,
                    'max_products' => $this->plan->max_products,
                    'max_stores' => $this->plan->max_stores,
                    'features' => $this->plan->effectiveFeatures(),
                    'is_active' => $this->plan->is_active,
                ];
            }),

            'products_usage' => $productsUsage,
            'stores_usage' => $storesUsage,
        ];
    }
}
