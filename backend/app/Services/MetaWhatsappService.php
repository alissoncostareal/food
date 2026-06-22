<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaWhatsappService
{
    public function isConfigured(): bool
    {
        if ($this->isTestMode()) {
            return true;
        }

        return (bool) config('services.meta_whatsapp.enabled')
            && filled(config('services.meta_whatsapp.app_id'))
            && filled(config('services.meta_whatsapp.app_secret'));
    }

    public function isTestMode(): bool
    {
        return (bool) config('services.meta_whatsapp.test_mode');
    }

    public function configurationStatus(): array
    {
        $missing = [];

        if (! (bool) config('services.meta_whatsapp.enabled')) {
            $missing[] = 'META_WHATSAPP_ENABLED';
        }

        if (! filled(config('services.meta_whatsapp.app_id'))) {
            $missing[] = 'META_WHATSAPP_APP_ID';
        }

        if (! filled(config('services.meta_whatsapp.app_secret'))) {
            $missing[] = 'META_WHATSAPP_APP_SECRET';
        }

        if (! filled(config('services.meta_whatsapp.embedded_signup_config_id'))) {
            $missing[] = 'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID';
        }

        return [
            'enabled' => (bool) config('services.meta_whatsapp.enabled'),
            'test_mode' => $this->isTestMode(),
            'configured' => $this->isConfigured(),
            'embedded_signup_ready' => $this->isEmbeddedSignupReady(),
            'missing' => $missing,
            'app_id' => config('services.meta_whatsapp.app_id'),
            'embedded_signup_config_id' => config('services.meta_whatsapp.embedded_signup_config_id'),
            'graph_version' => $this->graphVersion(),
        ];
    }

    public function isEmbeddedSignupReady(): bool
    {
        return $this->isConfigured()
            && filled(config('services.meta_whatsapp.embedded_signup_config_id'));
    }

    public function embeddedSignupConfig(): array
    {
        return [
            'app_id' => (string) config('services.meta_whatsapp.app_id'),
            'config_id' => (string) config('services.meta_whatsapp.embedded_signup_config_id'),
            'graph_version' => $this->graphVersion(),
        ];
    }

    public function exchangeCodeForToken(string $code): array
    {
        $response = $this->client()->get('/oauth/access_token', [
            'client_id' => config('services.meta_whatsapp.app_id'),
            'client_secret' => config('services.meta_whatsapp.app_secret'),
            'code' => $code,
        ]);

        $response->throw();

        return $response->json();
    }

    public function fetchPhoneNumberDetails(string $phoneNumberId, string $accessToken): array
    {
        $response = $this->client($accessToken)->get("/{$phoneNumberId}", [
            'fields' => 'display_phone_number,verified_name,quality_rating',
        ]);

        $response->throw();

        return $response->json();
    }

    public function registerPhoneNumber(string $phoneNumberId, string $accessToken, ?string $pin = null): void
    {
        $response = $this->client($accessToken)->post("/{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin ?: (string) config('services.meta_whatsapp.default_pin', '123456'),
        ]);

        if ($response->status() === 400 && str_contains(strtolower($response->body()), 'already')) {
            return;
        }

        $response->throw();
    }

    public function subscribeAppToWaba(string $wabaId, string $accessToken): void
    {
        $response = $this->client($accessToken)->post("/{$wabaId}/subscribed_apps");

        if ($response->status() === 400 && str_contains(strtolower($response->body()), 'already')) {
            return;
        }

        $response->throw();
    }

    public function sendAuthenticationOtp(
        string $phoneNumberId,
        string $accessToken,
        string $phone,
        string $code,
        ?string $templateName = null,
        ?string $language = null,
    ): array {
        if ($this->isTestMode()) {
            Log::info('Meta WhatsApp test mode: OTP skipped', [
                'phone' => $phone,
                'code' => $code,
            ]);

            return ['messages' => [['id' => 'test-otp-message']]];
        }

        $templateName = trim((string) ($templateName ?: config('services.meta_whatsapp.otp_template_name')));

        if ($templateName === '') {
            throw new \RuntimeException('Template de autenticação Meta não configurado (META_WHATSAPP_OTP_TEMPLATE_NAME).');
        }

        $language = trim((string) ($language ?: config('services.meta_whatsapp.otp_template_language', 'pt_BR')));

        $response = $this->client($accessToken)->post("/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $code],
                        ],
                    ],
                ],
            ],
        ]);

        $response->throw();

        return $response->json();
    }

    public function sendTextToPhoneNumberId(
        string $phoneNumberId,
        string $accessToken,
        string $phone,
        string $text,
    ): array {
        if ($this->isTestMode()) {
            Log::info('Meta WhatsApp test mode: message skipped', [
                'phone' => $phone,
                'text' => $text,
            ]);

            return ['messages' => [['id' => 'test-message']]];
        }

        $response = $this->client($accessToken)->post("/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);

        $response->throw();

        return $response->json();
    }

    public function sendText(Store $store, string $phone, string $text): array
    {
        if ($this->isTestMode()) {
            Log::info('Meta WhatsApp test mode: message skipped', [
                'store_id' => $store->id,
                'phone' => $phone,
                'text' => $text,
            ]);

            return ['messages' => [['id' => 'test-message']]];
        }

        $phoneNumberId = trim((string) $store->meta_phone_number_id);
        $accessToken = $store->meta_access_token;

        if ($phoneNumberId === '' || blank($accessToken)) {
            throw new \RuntimeException('WhatsApp Meta da loja não está configurado.');
        }

        $response = $this->client($accessToken)->post("/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);

        $response->throw();

        return $response->json();
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        while (str_starts_with($digits, '0') && strlen($digits) > 11) {
            $digits = substr($digits, 1);
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return $digits;
        }

        if (strlen($digits) === 11) {
            return '55'.$digits;
        }

        if (strlen($digits) === 10) {
            return '55'.substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return $digits;
    }

    private function client(?string $accessToken = null): PendingRequest
    {
        $request = Http::baseUrl('https://graph.facebook.com/'.$this->graphVersion())
            ->timeout((int) config('services.meta_whatsapp.timeout', 30))
            ->acceptJson();

        if (filled($accessToken)) {
            $request = $request->withToken($accessToken);
        }

        return $request;
    }

    private function graphVersion(): string
    {
        return trim((string) config('services.meta_whatsapp.graph_version', 'v21.0'), '/');
    }
}
