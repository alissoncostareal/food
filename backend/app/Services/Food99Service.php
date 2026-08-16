<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class Food99Service
{
    public const WEBHOOK_PATH = '/api/v1/integrations/food99/webhook';

    public function resolveWebhookUrl(): string
    {
        $configured = trim((string) config('services.food99.webhook_url'));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        return $appUrl.self::WEBHOOK_PATH;
    }

    public function validateWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.food99.webhook_secret');

        if ($secret === '') {
            if (app()->environment('production')) {
                Log::warning('99Food webhook: FOOD99_WEBHOOK_SECRET não configurado; aceitando até a homologação.');
            }

            return true;
        }

        if (blank($signature)) {
            Log::warning('99Food webhook rejeitado: assinatura ausente');

            return false;
        }

        $signature = trim($signature);

        if (str_starts_with(strtolower($signature), 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expectedHex = hash_hmac('sha256', $rawBody, $secret);
        $expectedB64 = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals(strtolower($expectedHex), strtolower($signature))
            || hash_equals($expectedB64, $signature);
    }
}
