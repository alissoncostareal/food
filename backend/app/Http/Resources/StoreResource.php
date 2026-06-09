<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $productsUsage = null;

        if ($this->resource && method_exists($this->resource, 'maxProductsAllowed')) {
            $maxProducts = $this->maxProductsAllowed();
            $currentProducts = $this->products()->count();

            $productsUsage = [
                'current' => $currentProducts,
                'limit' => $maxProducts,
                'is_unlimited' => is_null($maxProducts),
                'reached' => !is_null($maxProducts) && $currentProducts >= $maxProducts,
            ];
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
            'is_open' => (bool) $this->is_open,
            'is_open_now' => $this->is_open_now,
            'slug' => $this->slug,
            'instagram_link' => $this->instagram_link,
            'whatsapp_number' => $this->whatsapp_number,
            'business_hours' => $this->business_hours,
            'address' => $this->address,
            'delivery_fee' => $this->delivery_fee,
            'primary_color' => $this->primary_color,

            'plan_id' => $this->plan_id,
            'plan_type' => $this->plan_type,
            'subscription_status' => $this->subscription_status,
            'subscription_ends_at' => $this->subscription_ends_at,
            'complimentary_until' => $this->complimentary_until,
            'complimentary_reason' => $this->complimentary_reason,
            'has_active_subscription' => method_exists($this->resource, 'hasActiveSubscription')
                ? $this->hasActiveSubscription()
                : false,

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
                    'features' => $this->plan->features,
                    'is_active' => $this->plan->is_active,
                ];
            }),

            'products_usage' => $productsUsage,
        ];
    }
}
