<?php

namespace App\Services;

use App\Models\OptionItem;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

class IfoodImageService
{
    public function resolvePublicUrl(?string $imagePath): ?string
    {
        $imagePath = self::normalizeUploadPath((string) $imagePath);

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

        return $base.'/'.$imagePath;
    }

    public static function normalizeUploadPath(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $path = parse_url($raw, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                return '';
            }

            foreach (['/pratos/', '/image/upload/'] as $marker) {
                $position = strpos($path, $marker);

                if ($position !== false) {
                    return ltrim(substr($path, $position + strlen($marker)), '/');
                }
            }

            return ltrim($path, '/');
        }

        return ltrim($raw, '/');
    }

    public static function isValidCatalogImagePath(string $imagePath): bool
    {
        $imagePath = trim($imagePath);

        return $imagePath !== ''
            && str_contains($imagePath, '/')
            && preg_match('/\.(jpe?g|png)$/i', $imagePath) === 1;
    }

    public function isCdnAccessible(string $imagePath, int $attempts = 3): bool
    {
        $url = $this->resolvePublicUrl($imagePath);

        if ($url === null) {
            return false;
        }

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = Http::timeout(20)->get($url);

                if ($response->successful()) {
                    $contentType = strtolower((string) $response->header('Content-Type'));

                    if (
                        $contentType === ''
                        || str_contains($contentType, 'image/')
                        || str_contains($contentType, 'octet-stream')
                    ) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // retry
            }

            if ($attempt < $attempts - 1) {
                usleep(500_000);
            }
        }

        return false;
    }

    public function extractUploadPath(mixed $body): ?string
    {
        if (is_string($body)) {
            $trimmed = trim($body);

            if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                return null;
            }

            $path = self::normalizeUploadPath($trimmed);

            return self::isValidCatalogImagePath($path) ? $path : null;
        }

        if (! is_array($body)) {
            return null;
        }

        foreach (['imagePath', 'path', 'image.path', 'data.imagePath', 'url'] as $key) {
            $value = data_get($body, $key);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $path = self::normalizeUploadPath($value);

            if (self::isValidCatalogImagePath($path)) {
                return $path;
            }
        }

        return null;
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
