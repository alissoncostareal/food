<?php

namespace App\Services;

use App\Models\Store;
use App\Support\IntegrationErrorReporter;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaWhatsappProvisioningService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly MetaWhatsappService $meta
    ) {}

    public function connectionPayload(Store $store): array
    {
        $error = IntegrationErrorReporter::parseStored($store->meta_last_error);

        return [
            'status' => $store->meta_whatsapp_status ?: self::STATUS_PENDING,
            'connected_at' => $store->meta_connected_at?->toIso8601String(),
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'waba_id' => $store->meta_waba_id,
            'phone_number_id' => $store->meta_phone_number_id,
            'display_phone' => $store->meta_display_phone,
            'whatsapp_number' => $store->whatsapp_number ?: $this->digitsFromDisplayPhone($store->meta_display_phone),
            'test_mode' => $this->meta->isTestMode(),
        ];
    }

    public function completeEmbeddedSignup(Store $store, array $data): Store
    {
        if (! $this->meta->isConfigured()) {
            throw new \RuntimeException('WhatsApp Meta não configurado no servidor.');
        }

        if ($this->meta->isTestMode()) {
            $store->update([
                'whatsapp_provider' => Store::WHATSAPP_PROVIDER_META,
                'meta_waba_id' => $data['waba_id'] ?? 'test-waba',
                'meta_phone_number_id' => $data['phone_number_id'] ?? 'test-phone-id',
                'meta_access_token' => 'test-token',
                'meta_whatsapp_status' => self::STATUS_CONNECTED,
                'meta_connected_at' => now(),
                'meta_last_error' => null,
                'meta_display_phone' => $data['display_phone'] ?? $store->whatsapp_number,
            ]);

            return $store->fresh(['plan']);
        }

        $code = trim((string) ($data['code'] ?? ''));
        $wabaId = trim((string) ($data['waba_id'] ?? ''));
        $phoneNumberId = trim((string) ($data['phone_number_id'] ?? ''));

        if ($code === '' || $wabaId === '' || $phoneNumberId === '') {
            throw new \RuntimeException('Dados incompletos do cadastro incorporado da Meta.');
        }

        $store->update([
            'whatsapp_provider' => Store::WHATSAPP_PROVIDER_META,
            'meta_whatsapp_status' => self::STATUS_CONNECTING,
            'meta_last_error' => null,
        ]);

        try {
            $tokenPayload = $this->meta->exchangeCodeForToken($code);
            $accessToken = trim((string) ($tokenPayload['access_token'] ?? ''));

            if ($accessToken === '') {
                throw new \RuntimeException('Meta não retornou token de acesso.');
            }

            $this->meta->subscribeAppToWaba($wabaId, $accessToken);
            $this->meta->registerPhoneNumber(
                $phoneNumberId,
                $accessToken,
                filled($data['pin'] ?? null) ? (string) $data['pin'] : null
            );

            $details = $this->meta->fetchPhoneNumberDetails($phoneNumberId, $accessToken);
            $displayPhone = trim((string) ($details['display_phone_number'] ?? ''));
            $normalizedPhone = $this->digitsFromDisplayPhone($displayPhone);

            $store->update([
                'meta_waba_id' => $wabaId,
                'meta_phone_number_id' => $phoneNumberId,
                'meta_access_token' => $accessToken,
                'meta_whatsapp_status' => self::STATUS_CONNECTED,
                'meta_connected_at' => now(),
                'meta_last_error' => null,
                'meta_display_phone' => $displayPhone !== '' ? $displayPhone : $store->meta_display_phone,
                'whatsapp_number' => $normalizedPhone !== '' ? $normalizedPhone : $store->whatsapp_number,
            ]);

            return $store->fresh(['plan']);
        } catch (Throwable $e) {
            Log::warning('Meta WhatsApp embedded signup failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return $this->markError($store, $e->getMessage());
        }
    }

    public function disconnect(Store $store): Store
    {
        $store->update([
            'meta_waba_id' => null,
            'meta_phone_number_id' => null,
            'meta_access_token' => null,
            'meta_whatsapp_status' => self::STATUS_PENDING,
            'meta_connected_at' => null,
            'meta_last_error' => null,
            'meta_display_phone' => null,
        ]);

        return $store->fresh(['plan']);
    }

    public function isConnected(Store $store): bool
    {
        if ($this->meta->isTestMode() && $store->usesMetaWhatsapp()) {
            return $store->meta_whatsapp_status === self::STATUS_CONNECTED;
        }

        return $store->meta_whatsapp_status === self::STATUS_CONNECTED
            && filled($store->meta_phone_number_id)
            && filled($store->meta_access_token);
    }

    private function markError(Store $store, string $message): Store
    {
        $store->update([
            'meta_whatsapp_status' => self::STATUS_ERROR,
            'meta_last_error' => IntegrationErrorReporter::storeMessage('whatsapp', 'meta_signup', $message, [
                'store_id' => $store->id,
            ]),
        ]);

        return $store->fresh(['plan']);
    }

    private function digitsFromDisplayPhone(?string $displayPhone): string
    {
        if (blank($displayPhone)) {
            return '';
        }

        return $this->meta->normalizePhone($displayPhone);
    }
}
