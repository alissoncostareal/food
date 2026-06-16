<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'position' => $this->position,
            'ifood_category_id' => $this->ifood_category_id,
            'ifood_synced' => filled($this->ifood_category_id),
            'products' => ProductResource::collection($this->whenLoaded('products'))
        ];
    }
}
