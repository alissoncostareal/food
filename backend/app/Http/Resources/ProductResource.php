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
            'name' => $this->name,
            'description' => $this->description,

            // Mantendo o tipo numérico para cálculos no Front
            'price' => (float) $this->price,
            'price_formatted' => 'R$ ' . number_format($this->price, 2, ',', '.'),

            // URL absoluta da imagem
            'image' => $this->image ? asset('storage/' . $this->image) : null,

            // Categoria com fallback
            'category' => [
                'id'   => $this->product_category_id,
                'name' => $this->category->name ?? 'Geral',
            ],

            // RELACIONAMENTOS
            // Aqui está o segredo: mapeamos 'optionGroups' (Model) para 'option_groups' (JSON/Vue)
            'store' => new StoreResource($this->whenLoaded('store')),

            // Usamos 'optionGroups' porque é o nome exato da function no seu Model Product.php
            'option_groups' => OptionGroupResource::collection($this->optionGroups),

            'created_at' => $this->created_at ? $this->created_at->format('d/m/Y') : null,
        ];
    }
}
