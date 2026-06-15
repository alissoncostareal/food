<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::get('/storage/{path}', function (string $path) {
    $path = ltrim(str_replace(['..', '\\'], '', $path), '/');

    if ($path === '') {
        abort(404);
    }

    $diskName = (string) config('filesystems.media_disk', 'public');
    $disk = Storage::disk($diskName);

    if (! $disk->exists($path)) {
        abort(404);
    }

    if ($diskName === 's3') {
        return redirect($disk->url($path), 301);
    }

    $absolute = $disk->path($path);
    $mime = $disk->mimeType($path) ?: 'application/octet-stream';

    return response()->file($absolute, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->where('path', '.*');

Route::get('/', function () {
    return response()->json(['message' => 'API Online']);
});

Route::get('/billing', function () {
    $adminUrl = rtrim((string) config('services.admin.url'), '/');
    $query = request()->getQueryString();
    $target = $adminUrl . '/billing' . ($query ? '?' . $query : '');

    return response(
        '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PartiuMenu</title></head><body style="font-family:Arial,sans-serif;padding:32px"><p>Finalizando retorno para o PartiuMenu...</p><p><a href="' . e($target) . '">Continuar para o painel</a></p><script>window.top.location.href = ' . json_encode($target) . ';</script></body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
});
