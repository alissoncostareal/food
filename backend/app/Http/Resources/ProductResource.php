<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\StoreResource;
use App\Http\Resources\OptionGroupResource;

class ProductResource extends JsonResource
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
            'store_id' => $this->store_id,
            'name' => $this->name,
            'description' => $this->description,

            // Formatação de preço para o frontend
            'price' => (float) $this->price,
            'price_formatted' => 'R$ ' . number_format($this->price, 2, ',', '.'),

            // Garante que a URL da imagem seja absoluta
            'image_url' => $this->image ? asset('storage/' . $this->image) : asset('images/default-product.png'),

            // Relacionamentos (opcional, carrega se estiver presente no model)
            'store' => new StoreResource($this->whenLoaded('store')),
            'option_groups' => OptionGroupResource::collection($this->whenLoaded('optionGroups')),
            'created_at' => $this->created_at->format('d/m/Y'),
        ];
    }
}
