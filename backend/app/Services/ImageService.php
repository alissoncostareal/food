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
        $binary = file_get_contents($file->getRealPath()) ?: '';

        if ($binary !== '') {
            $normalized = self::normalizeBinaryForIfood($binary);

            if ($normalized !== null) {
                $path = trim($folder, '/').'/'.Str::uuid().'.jpg';
                $disk->put($path, $normalized['binary'], ['visibility' => 'public']);

                if ($disk->exists($path)) {
                    return $path;
                }
            }
        }

        throw new RuntimeException('Não foi possível processar a imagem. Use JPG ou PNG.');
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

    public static function readFailureHint(?string $stored, string $label = 'imagem'): string
    {
        if (blank($stored)) {
            return "Envie novamente a foto de {$label}.";
        }

        $stored = trim($stored);

        if (str_starts_with($stored, 'blob:') || str_starts_with($stored, 'data:')) {
            return "Salve {$label} no cardápio antes de publicar no iFood (a foto ainda não foi enviada ao servidor).";
        }

        if (! function_exists('imagecreatefromstring')) {
            return 'O servidor não tem a extensão GD do PHP para processar imagens. Reimplante a API.';
        }

        $path = self::resolveStoredPath($stored);
        $onDisk = $path !== null && self::disk()->exists($path);

        if ($onDisk) {
            $binary = self::disk()->get($path);

            if (! is_string($binary) || $binary === '') {
                return "O arquivo de {$label} está vazio no storage. Reenvie a foto.";
            }

            if (! self::isValidImageBinary($binary)) {
                return "O arquivo de {$label} não é uma imagem válida. Reenvie em JPG ou PNG.";
            }

            if (self::normalizeBinaryForIfood($binary) === null) {
                return "Não foi possível processar {$label} para o iFood. Reenvie em JPG ou PNG.";
            }
        }

        $url = self::publicUrl($stored);

        if ($url !== null) {
            try {
                $response = Http::timeout(10)->get($url);

                if (! $response->successful()) {
                    return "Arquivo de {$label} não encontrado no storage (HTTP {$response->status()}). Reenvie a foto.";
                }

                if (! self::isValidImageBinary($response->body())) {
                    return "A URL de {$label} não retorna um arquivo de imagem válido. Reenvie a foto.";
                }
            } catch (\Throwable) {
                return "Não foi possível acessar a URL pública de {$label}. Reenvie a foto.";
            }
        }

        if (! $onDisk) {
            return "Arquivo de {$label} não encontrado no storage. Reenvie a foto.";
        }

        return "Reenvie {$label} em JPG ou PNG.";
    }

    public static function isValidImageBinary(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($binary);

            if ($image !== false) {
                imagedestroy($image);

                return true;
            }
        }

        return self::hasKnownImageSignature($binary);
    }

    private static function hasKnownImageSignature(string $binary): bool
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return true;
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return true;
        }

        return str_starts_with($binary, 'RIFF')
            && strlen($binary) >= 12
            && substr($binary, 8, 4) === 'WEBP';
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

        if (str_starts_with($stored, 'blob:') || str_starts_with($stored, 'data:')) {
            return null;
        }

        $path = self::resolveStoredPath($stored);

        if ($path !== null && self::disk()->exists($path)) {
            $binary = self::disk()->get($path);

            if (is_string($binary) && $binary !== '' && self::isValidImageBinary($binary)) {
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

            $binary = $response->body();

            if ($binary === '') {
                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if (
                $contentType !== ''
                && ! str_contains($contentType, 'image/')
                && ! str_contains($contentType, 'octet-stream')
                && (str_contains($contentType, 'text/') || str_contains($contentType, 'application/json'))
            ) {
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
        $normalized = self::normalizeBinaryForIfood($binary);

        if ($normalized === null) {
            return null;
        }

        $binary = $normalized['binary'];
        $extension = $normalized['extension'];

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

    public static function normalizeBinaryForIfood(string $binary): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            $extension = strtolower(self::guessExtensionFromBinary($binary));

            if (! in_array($extension, ['jpg', 'png'], true)) {
                return null;
            }

            return [
                'binary' => $binary,
                'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            ];
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

        $minWidth = 800;
        $minHeight = 600;

        if ($width < $minWidth || $height < $minHeight) {
            $scale = max($minWidth / $width, $minHeight / $height);
            $targetWidth = (int) round($width * $scale);
            $targetHeight = (int) round($height * $scale);
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($resized === false) {
                imagedestroy($image);

                return null;
            }

            $background = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $background);
            imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $width,
                $height
            );
            imagedestroy($image);
            $image = $resized;
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

        if ($written === false || ! is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return [
            'binary' => $jpeg,
            'extension' => 'jpg',
        ];
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
