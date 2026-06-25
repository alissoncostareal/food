<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\BrazilPhone;
use App\Support\IntegrationErrorReporter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformWhatsappService
{
    public const PROVIDER_META = 'meta';

    private const KEY_STATUS = 'platform_whatsapp_status';

    private const KEY_CONNECTED_AT = 'platform_whatsapp_connected_at';

    private const KEY_NUMBER = 'platform_whatsapp_number';

    private const KEY_LAST_ERROR = 'platform_whatsapp_last_error';

    private const KEY_PROVIDER = 'platform_whatsapp_provider';

    private const KEY_META_WABA_ID = 'platform_meta_waba_id';

    private const KEY_META_PHONE_NUMBER_ID = 'platform_meta_phone_number_id';

    private const KEY_META_ACCESS_TOKEN = 'platform_meta_access_token';

    private const KEY_META_DISPLAY_PHONE = 'platform_meta_display_phone';

    public function __construct(
        private readonly MetaWhatsappService $meta,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER_META;
    }

    public function isConnected(): bool
    {
        if ($this->status() !== WhatsappProvisioningService::STATUS_CONNECTED) {
            return false;
        }

        return filled($this->metaPhoneNumberId()) && filled($this->metaAccessToken());
    }

    public function connectionPayload(): array
    {
        $error = IntegrationErrorReporter::parseStored(
            PlatformSetting::get(self::KEY_LAST_ERROR)
        );

        $status = PlatformSetting::get(self::KEY_STATUS, WhatsappProvisioningService::STATUS_PENDING);
        $connectedAt = PlatformSetting::get(self::KEY_CONNECTED_AT);

        PlatformSetting::set(self::KEY_PROVIDER, self::PROVIDER_META);

        return [
            'scope' => 'platform',
            'purpose' => 'otp',
            'purpose_label' => 'Login dos clientes (código OTP)',
            'provider' => self::PROVIDER_META,
            'provider_labels' => [
                self::PROVIDER_META => 'Oficial (Meta)',
            ],
            'status' => $status ?: WhatsappProvisioningService::STATUS_PENDING,
            'connected_at' => filled($connectedAt) ? $connectedAt : null,
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'whatsapp_number' => PlatformSetting::get(self::KEY_NUMBER),
            'whatsapp_number_display' => BrazilPhone::formatForDisplay(PlatformSetting::get(self::KEY_NUMBER)),
            'whatsapp_number_missing' => blank(PlatformSetting::get(self::KEY_NUMBER)),
            'meta' => array_merge($this->meta->configurationStatus(), [
                'otp_template_name' => config('services.meta_whatsapp.otp_template_name'),
                'otp_template_language' => config('services.meta_whatsapp.otp_template_language', 'pt_BR'),
            ]),
            'test_mode' => $this->meta->isTestMode(),
            'phone_number_id' => $this->metaPhoneNumberId(),
            'display_phone' => PlatformSetting::get(self::KEY_META_DISPLAY_PHONE),
            'waba_id' => PlatformSetting::get(self::KEY_META_WABA_ID),
        ];
    }

    public function completeMetaSignup(array $data): void
    {
        if (! $this->meta->isConfigured()) {
            throw new \RuntimeException('WhatsApp Meta não configurado no servidor.');
        }

        PlatformSetting::set(self::KEY_PROVIDER, self::PROVIDER_META);
        $this->setStatus('connecting');
        PlatformSetting::set(self::KEY_LAST_ERROR, '');

        if ($this->meta->isTestMode()) {
            PlatformSetting::set(self::KEY_META_WABA_ID, $data['waba_id'] ?? 'test-waba');
            PlatformSetting::set(self::KEY_META_PHONE_NUMBER_ID, $data['phone_number_id'] ?? 'test-phone-id');
            $this->storeMetaAccessToken('test-token');
            PlatformSetting::set(self::KEY_META_DISPLAY_PHONE, $data['display_phone'] ?? '');
            $this->markConnected();
            if (filled($data['display_phone'] ?? null)) {
                PlatformSetting::set(self::KEY_NUMBER, $this->meta->normalizePhone((string) $data['display_phone']));
            }

            return;
        }

        $code = trim((string) ($data['code'] ?? ''));
        $wabaId = trim((string) ($data['waba_id'] ?? ''));
        $phoneNumberId = trim((string) ($data['phone_number_id'] ?? ''));

        if ($code === '' || $wabaId === '' || $phoneNumberId === '') {
            throw new \RuntimeException('Dados incompletos do cadastro incorporado da Meta.');
        }

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

            PlatformSetting::set(self::KEY_META_WABA_ID, $wabaId);
            PlatformSetting::set(self::KEY_META_PHONE_NUMBER_ID, $phoneNumberId);
            $this->storeMetaAccessToken($accessToken);
            PlatformSetting::set(self::KEY_META_DISPLAY_PHONE, $displayPhone);

            if ($displayPhone !== '') {
                PlatformSetting::set(self::KEY_NUMBER, $this->meta->normalizePhone($displayPhone));
            }

            $this->markConnected();
        } catch (Throwable $e) {
            Log::error('Platform Meta signup failed', ['error' => $e->getMessage()]);
            $this->markError($e, 'meta_signup');
            throw $e;
        }
    }

    public function disconnectMeta(): void
    {
        PlatformSetting::set(self::KEY_META_WABA_ID, '');
        PlatformSetting::set(self::KEY_META_PHONE_NUMBER_ID, '');
        $this->storeMetaAccessToken('');
        PlatformSetting::set(self::KEY_META_DISPLAY_PHONE, '');
        $this->setStatus(WhatsappProvisioningService::STATUS_PENDING);
        PlatformSetting::set(self::KEY_CONNECTED_AT, '');
        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    public function sendOtp(string $phone, string $code): void
    {
        if (! $this->isConnected()) {
            throw new \RuntimeException('WhatsApp da plataforma não está conectado. Conecte em Super Admin → WhatsApp.');
        }

        $this->sendOtpViaMeta($phone, $code);
    }

    public function sendTestMessage(string $phone): void
    {
        if (! $this->isConnected()) {
            throw new \RuntimeException('Conecte o WhatsApp da plataforma antes de enviar o teste.');
        }

        $normalizedDestination = $this->meta->normalizePhone($phone);
        $connectedNumber = PlatformSetting::get(self::KEY_NUMBER);

        if (filled($connectedNumber) && $normalizedDestination === $connectedNumber) {
            throw new \RuntimeException('Envie o teste para outro número WhatsApp, não para o chip conectado.');
        }

        $text = 'Teste PartiuMenu — esta conta envia códigos OTP de login para os clientes.';

        try {
            $this->meta->sendTextToPhoneNumberId(
                $this->metaPhoneNumberId(),
                $this->metaAccessToken(),
                $phone,
                $text
            );

            PlatformSetting::set(self::KEY_LAST_ERROR, '');
        } catch (Throwable $e) {
            $this->storeLastError($e, 'test_message');

            throw $e;
        }
    }

    public function saveConnectedNumber(string $phone): void
    {
        $normalized = $this->meta->normalizePhone($phone);

        if (strlen($normalized) < 12) {
            throw new \InvalidArgumentException('Informe o número do chip com DDD.');
        }

        PlatformSetting::set(self::KEY_NUMBER, $normalized);
        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    private function sendOtpViaMeta(string $phone, string $code): void
    {
        if (! $this->meta->isConfigured()) {
            throw new \RuntimeException('WhatsApp Meta não configurado no servidor.');
        }

        $this->meta->sendAuthenticationOtp(
            $this->metaPhoneNumberId(),
            $this->metaAccessToken(),
            $phone,
            $code
        );

        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    private function metaPhoneNumberId(): string
    {
        return trim((string) PlatformSetting::get(self::KEY_META_PHONE_NUMBER_ID));
    }

    private function metaAccessToken(): string
    {
        $stored = trim((string) PlatformSetting::get(self::KEY_META_ACCESS_TOKEN));

        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return $stored;
        }
    }

    private function storeMetaAccessToken(string $token): void
    {
        if ($token === '') {
            PlatformSetting::set(self::KEY_META_ACCESS_TOKEN, '');

            return;
        }

        PlatformSetting::set(self::KEY_META_ACCESS_TOKEN, Crypt::encryptString($token));
    }

    private function storeLastError(Throwable|string $error, string $action): void
    {
        $message = $error instanceof Throwable
            ? IntegrationErrorReporter::sanitize($error->getMessage())
            : IntegrationErrorReporter::sanitize((string) $error);

        PlatformSetting::set(self::KEY_LAST_ERROR, $message.' (ação: '.$action.')');
    }

    private function status(): string
    {
        return (string) PlatformSetting::get(self::KEY_STATUS, WhatsappProvisioningService::STATUS_PENDING);
    }

    private function setStatus(string $status): void
    {
        PlatformSetting::set(self::KEY_STATUS, $status);
    }

    private function markConnected(): void
    {
        $connectedAt = PlatformSetting::get(self::KEY_CONNECTED_AT);

        $this->setStatus(WhatsappProvisioningService::STATUS_CONNECTED);
        PlatformSetting::set(self::KEY_CONNECTED_AT, $connectedAt ?: now()->toIso8601String());
        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    private function markError(Throwable|string $error, string $action = 'provision'): void
    {
        $message = $error instanceof Throwable
            ? IntegrationErrorReporter::storeMessage('whatsapp', 'platform_'.$action, $error, ['scope' => 'platform'])
            : (string) $error;

        PlatformSetting::set(self::KEY_LAST_ERROR, $message);
        $this->setStatus(WhatsappProvisioningService::STATUS_ERROR);
    }
}
