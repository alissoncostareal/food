<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OptionGroupResource extends JsonResource
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
            'product_id' => $this->product_id,
            'name' => $this->name,
            'min_selected' => (int) $this->min_selected,
            'max_selected' => (int) $this->max_selected,
            'items' => OptionItemResource::collection($this->whenLoaded('optionItems')),
        ];
    }
}
