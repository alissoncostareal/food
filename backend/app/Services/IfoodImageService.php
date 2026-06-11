<?php

namespace App\Services;

use App\Models\OptionItem;
use App\Models\Product;

class IfoodImageService
{
    public function resolvePublicUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string) $imagePath);

        if ($imagePath === '') {
            return null;
        }

        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            return $imagePath;
        }

        $base = rtrim(
            (string) config(
                'services.ifood.image_cdn_base',
                'https://static-images.ifood.com.br/image/upload/t_medium/pratos'
            ),
            '/'
        );

        return $base . '/' . ltrim($imagePath, '/');
    }

    public function extractImagePath(array $payload): ?string
    {
        foreach ([
            data_get($payload, 'imagePath'),
            data_get($payload, 'image'),
            data_get($payload, 'product.imagePath'),
            data_get($payload, 'product.image'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    public function importForProduct(Product $product, ?string $imagePath): bool
    {
        $url = $this->resolvePublicUrl($imagePath);

        if ($url === null) {
            return false;
        }

        $storedPath = ImageService::storeFromUrl($url, 'products/ifood', $product->image);

        if ($storedPath === null) {
            return false;
        }

        $product->update(['image' => $storedPath]);

        return true;
    }

    public function importForOptionItem(OptionItem $item, ?string $imagePath): bool
    {
        $url = $this->resolvePublicUrl($imagePath);

        if ($url === null) {
            return false;
        }

        $storedPath = ImageService::storeFromUrl($url, 'products/options/ifood', $item->getRawOriginal('image_url'));

        if ($storedPath === null) {
            return false;
        }

        $item->update(['image_url' => $storedPath]);

        return true;
    }
}
