<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    public static function upload(UploadedFile $file, string $folder): string
    {
        // Salva a imagem com um nome único e retorna o caminho
        return $file->store($folder, 'public');
    }

    public static function storeFromBase64(string $payload, string $folder, ?string $replacePath = null): ?string
    {
        try {
            $normalized = trim($payload);

            if (str_contains($normalized, ',')) {
                $normalized = (string) substr($normalized, (int) strrpos($normalized, ',') + 1);
            }

            $binary = base64_decode($normalized, true);

            if ($binary === false || $binary === '') {
                return null;
            }

            $extension = self::guessExtensionFromBinary($binary);
            $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $extension;

            Storage::disk('public')->put($path, $binary);

            if ($replacePath !== null) {
                self::delete($replacePath);
            }

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function storeFromUrl(string $url, string $folder, ?string $replacePath = null): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            if ($body === '') {
                return null;
            }

            $extension = self::guessExtension($url, (string) $response->header('Content-Type'));
            $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $extension;

            Storage::disk('public')->put($path, $body);

            if ($replacePath !== null) {
                self::delete($replacePath);
            }

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private static function guessExtensionFromBinary(string $binary): string
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 0, 16), 'WEBP')) {
            return 'webp';
        }

        return 'jpg';
    }

    private static function guessExtension(string $url, string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path)) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                return $extension === 'jpeg' ? 'jpg' : $extension;
            }
        }

        return match (true) {
            str_contains(strtolower($contentType), 'png') => 'png',
            str_contains(strtolower($contentType), 'webp') => 'webp',
            default => 'jpg',
        };
    }
}
