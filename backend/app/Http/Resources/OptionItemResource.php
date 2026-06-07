<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OptionItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'option_group_id' => $this->option_group_id,
            'name'            => $this->name,
            'price'           => (float) $this->price,
            'price_formatted' => 'R$ ' . number_format($this->price, 2, ',', '.'),
            'image'       => $this->image_url ? asset('storage/' . $this->image_url) : null,
            'is_available'    => (bool) $this->is_available,
        ];
    }
}
