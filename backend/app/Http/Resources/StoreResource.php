<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
            'is_open' => (bool) $this->is_open,
            'slug' => $this->slug,
            'instagram_link' => $this->instagram_link,
            'whatsapp_number' => $this->whatsapp_number,
            'business_hours' => $this->business_hours,
            'address' => $this->address,
            'delivery_fee' => $this->delivery_fee,
            'primary_color' => $this->primary_color,
        ];
    }
}
