<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageService
{
    public static function upload(UploadedFile $file, string $folder): string
    {
        $disk = self::disk();
        $path = $file->storePublicly($folder, self::diskName());

        if (blank($path) || ! $disk->exists($path)) {
            throw new RuntimeException('Não foi possível salvar o arquivo de imagem no servidor.');
        }

        return $path;
    }

    public static function publicUrl(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $stored = trim($stored);

        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return self::ensureHttps($stored);
        }

        if (self::usesObjectStorage()) {
            return self::ensureHttps((string) self::disk()->url($stored));
        }

        $baseUrl = rtrim((string) (config('app.asset_url') ?: config('app.url')), '/');

        return self::ensureHttps($baseUrl.'/storage/'.ltrim($stored, '/'));
    }

    public static function deleteStored(?string $stored): void
    {
        $path = self::extractStoragePath($stored);

        if ($path) {
            self::delete($path);
        }
    }

    public static function extractStoragePath(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $stored = trim($stored);

        if (! str_contains($stored, '://')) {
            return ltrim($stored, '/');
        }

        $path = parse_url($stored, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        if (str_contains($path, '/storage/')) {
            return ltrim((string) substr($path, (int) strpos($path, '/storage/') + strlen('/storage/')), '/');
        }

        $publicBase = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

        if ($publicBase !== '' && str_starts_with($stored, $publicBase)) {
            return ltrim((string) substr($stored, strlen($publicBase)), '/');
        }

        return ltrim($path, '/');
    }

    public static function usesObjectStorage(): bool
    {
        return self::diskName() === 's3';
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

            self::disk()->put($path, $binary, ['visibility' => 'public']);

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

            self::disk()->put($path, $body, ['visibility' => 'public']);

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
        if ($path && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }
    }

    public static function toDataUri(?string $stored): ?string
    {
        $path = self::extractStoragePath($stored);

        if ($path === null || ! self::disk()->exists($path)) {
            return null;
        }

        $binary = self::disk()->get($path);

        if ($binary === '') {
            return null;
        }

        $extension = self::guessExtensionFromBinary($binary);
        $mime = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private static function diskName(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    private static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    private static function ensureHttps(string $url): string
    {
        if (app()->environment('production')) {
            return preg_replace('/^http:\/\//i', 'https://', $url) ?? $url;
        }

        return $url;
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
