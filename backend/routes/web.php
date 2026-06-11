<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

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
