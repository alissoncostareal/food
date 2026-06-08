<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'product_category_id' => $this->product_category_id,
            'name' => $this->name,
            'description' => $this->description,

            'price' => (float) $this->price,
            'price_formatted' => 'R$ ' . number_format($this->price, 2, ',', '.'),

            'image' => $this->image ? asset('storage/' . $this->image) : null,

            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'manage_stock' => (bool) $this->manage_stock,
            'stock_quantity' => $this->stock_quantity,

            'category' => [
                'id' => $this->product_category_id,
                'name' => $this->category->name ?? 'Geral',
            ],

            'store' => new StoreResource($this->whenLoaded('store')),
            'option_groups' => OptionGroupResource::collection($this->optionGroups),

            'created_at' => $this->created_at ? $this->created_at->format('d/m/Y') : null,
        ];
    }
}
