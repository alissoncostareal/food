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
            return self::stripBucketPrefix(ltrim((string) substr($stored, strlen($publicBase)), '/'));
        }

        return self::stripBucketPrefix(ltrim($path, '/'));
    }

    private static function stripBucketPrefix(string $path): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket', '');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path;
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
        $payload = self::readBinary($stored);

        if ($payload === null) {
            return null;
        }

        if (! self::isValidImageBinary($payload['binary'])) {
            return null;
        }

        return self::binaryToIfoodDataUri($payload['binary'], $payload['extension']);
    }

    public static function isValidImageBinary(string $binary): bool
    {
        if ($binary === '' || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return false;
        }

        imagedestroy($image);

        return true;
    }

    public static function resolveStoredPath(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $stored = trim($stored);

        if (! str_contains($stored, '://')) {
            return ltrim($stored, '/');
        }

        return self::extractStoragePath($stored);
    }

    public static function readBinary(?string $stored): ?array
    {
        if (blank($stored)) {
            return null;
        }

        $stored = trim($stored);
        $path = self::resolveStoredPath($stored);

        if ($path !== null && self::disk()->exists($path)) {
            $binary = self::disk()->get($path);

            if ($binary !== '') {
                return [
                    'binary' => $binary,
                    'extension' => self::guessExtensionFromBinary($binary),
                ];
            }
        }

        $url = self::publicUrl($stored);

        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if ($contentType !== '' && ! str_contains($contentType, 'image/')) {
                return null;
            }

            $binary = $response->body();

            if ($binary === '') {
                return null;
            }

            return [
                'binary' => $binary,
                'extension' => self::guessExtensionFromBinary($binary),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function binaryToIfoodDataUri(string $binary, string $extension): ?string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, ['jpg', 'png'], true)) {
            $converted = self::convertBinaryToJpeg($binary);

            if ($converted === null) {
                return null;
            }

            $binary = $converted;
            $extension = 'jpg';
        }

        if (strlen($binary) > 4_500_000) {
            $binary = self::resizeBinaryToMaxBytes($binary, 4_500_000);

            if ($binary === null) {
                return null;
            }

            $extension = 'jpg';
        }

        $mime = self::resolveIfoodMimeType($binary, $extension);

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private static function resolveIfoodMimeType(string $binary, string $extension): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $binary);
                finfo_close($finfo);

                if (is_string($detected)) {
                    if ($detected === 'image/jpeg' || $detected === 'image/jpg') {
                        return 'image/jpeg';
                    }

                    if ($detected === 'image/png') {
                        return 'image/png';
                    }
                }
            }
        }

        return $extension === 'png' ? 'image/png' : 'image/jpeg';
    }

    private static function convertBinaryToJpeg(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, false);

        ob_start();
        $written = imagejpeg($image, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        if ($written === false || $jpeg === false || $jpeg === '') {
            return null;
        }

        return $jpeg;
    }

    private static function resizeBinaryToMaxBytes(string $binary, int $maxBytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return null;
        }

        $quality = 88;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $scale = 1 - ($attempt * 0.12);
            $targetWidth = max(320, (int) round($width * $scale));
            $targetHeight = max(320, (int) round($height * $scale));
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($resized === false) {
                continue;
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $written = imagejpeg($resized, null, $quality);
            $jpeg = ob_get_clean();
            imagedestroy($resized);

            if ($written !== false && is_string($jpeg) && $jpeg !== '' && strlen($jpeg) <= $maxBytes) {
                imagedestroy($image);

                return $jpeg;
            }
        }

        imagedestroy($image);

        return null;
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

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'jpg';
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
