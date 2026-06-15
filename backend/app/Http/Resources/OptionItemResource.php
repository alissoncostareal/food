<?php

namespace App\Http\Resources;

use App\Services\ImageService;
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
        $path = $this->getRawOriginal('image_url');
        $url = ImageService::publicUrl($path);

        return [
            'id' => $this->id,
            'option_group_id' => $this->option_group_id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'price_formatted' => 'R$ ' . number_format($this->price, 2, ',', '.'),
            'image_url' => $url,
            'image' => $url,
            'image_path' => $path,
            'is_available' => (bool) $this->is_available,
        ];
    }
}
