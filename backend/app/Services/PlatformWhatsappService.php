<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\IntegrationErrorReporter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformWhatsappService
{
    public const PROVIDER_EVOLUTION = 'evolution';

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
        private readonly EvolutionService $evolution,
        private readonly MetaWhatsappService $meta,
    ) {}

    public function provider(): string
    {
        $provider = trim((string) PlatformSetting::get(self::KEY_PROVIDER, self::PROVIDER_EVOLUTION));

        return in_array($provider, [self::PROVIDER_EVOLUTION, self::PROVIDER_META], true)
            ? $provider
            : self::PROVIDER_EVOLUTION;
    }

    public function usesMeta(): bool
    {
        return $this->provider() === self::PROVIDER_META;
    }

    public function isConnected(): bool
    {
        if ($this->status() !== WhatsappProvisioningService::STATUS_CONNECTED) {
            return false;
        }

        if ($this->usesMeta()) {
            return filled($this->metaPhoneNumberId()) && filled($this->metaAccessToken());
        }

        return true;
    }

    public function connectionPayload(bool $refreshQr = true, bool $forceRefreshQr = false): array
    {
        $error = IntegrationErrorReporter::parseStored(
            PlatformSetting::get(self::KEY_LAST_ERROR)
        );

        $status = PlatformSetting::get(self::KEY_STATUS, WhatsappProvisioningService::STATUS_PENDING);
        $connectedAt = PlatformSetting::get(self::KEY_CONNECTED_AT);
        $instanceName = $this->instanceName();
        $provider = $this->provider();

        $payload = [
            'scope' => 'platform',
            'purpose' => 'otp',
            'purpose_label' => 'Login dos clientes (código OTP)',
            'provider' => $provider,
            'provider_labels' => [
                self::PROVIDER_EVOLUTION => 'Rápido (QR Code)',
                self::PROVIDER_META => 'Oficial (Meta)',
            ],
            'instance_name' => $instanceName,
            'instance_name_missing' => $provider === self::PROVIDER_EVOLUTION && blank($instanceName),
            'status' => $status ?: WhatsappProvisioningService::STATUS_PENDING,
            'connected_at' => filled($connectedAt) ? $connectedAt : null,
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'whatsapp_number' => PlatformSetting::get(self::KEY_NUMBER),
            'whatsapp_number_display' => $this->evolution->formatPhoneForDisplay(PlatformSetting::get(self::KEY_NUMBER)),
            'whatsapp_number_missing' => blank(PlatformSetting::get(self::KEY_NUMBER)),
            'evolution' => $this->evolution->configurationStatus(),
            'meta' => array_merge($this->meta->configurationStatus(), [
                'otp_template_name' => config('services.meta_whatsapp.otp_template_name'),
                'otp_template_language' => config('services.meta_whatsapp.otp_template_language', 'pt_BR'),
            ]),
            'test_mode' => $this->usesMeta()
                ? $this->meta->isTestMode()
                : $this->evolution->isTestMode(),
            'phone_number_id' => $this->metaPhoneNumberId(),
            'display_phone' => PlatformSetting::get(self::KEY_META_DISPLAY_PHONE),
            'waba_id' => PlatformSetting::get(self::KEY_META_WABA_ID),
        ];

        if ($provider !== self::PROVIDER_EVOLUTION) {
            return $payload;
        }

        if (in_array($payload['status'], [
            WhatsappProvisioningService::STATUS_AWAITING_QR,
            WhatsappProvisioningService::STATUS_PROVISIONING,
        ], true) && filled($instanceName)) {
            if ($refreshQr) {
                $payload['qrcode'] = $this->evolution->fetchQrCodeByName($instanceName, $forceRefreshQr);
            } else {
                $payload['qrcode'] = $this->evolution->cachedQrCodeByName($instanceName);
            }

            $payload['qrcode_expires_in'] = $this->evolution->qrCodeExpiresIn($instanceName);
        } elseif (filled($instanceName)) {
            $this->evolution->clearQrCache($instanceName);
        }

        if ($payload['status'] === WhatsappProvisioningService::STATUS_CONNECTED
            && blank($payload['whatsapp_number'])
            && filled($instanceName)) {
            $this->syncWhatsappNumberFromEvolution($instanceName);
            $payload['whatsapp_number'] = PlatformSetting::get(self::KEY_NUMBER);
            $payload['whatsapp_number_display'] = $this->evolution->formatPhoneForDisplay($payload['whatsapp_number']);
        }

        return $payload;
    }

    public function setProvider(string $provider): void
    {
        if (! in_array($provider, [self::PROVIDER_EVOLUTION, self::PROVIDER_META], true)) {
            throw new \InvalidArgumentException('Provedor WhatsApp inválido.');
        }

        if ($provider === $this->provider()) {
            return;
        }

        if ($provider === self::PROVIDER_META) {
            $this->disconnectEvolutionSilently();
        } else {
            $this->disconnectMeta();
        }

        PlatformSetting::set(self::KEY_PROVIDER, $provider);
    }

    public function completeMetaSignup(array $data): void
    {
        if (! $this->meta->isConfigured()) {
            throw new \RuntimeException('WhatsApp Meta não configurado no servidor.');
        }

        $this->setProvider(self::PROVIDER_META);
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

        if ($this->usesMeta()) {
            $this->sendOtpViaMeta($phone, $code);

            return;
        }

        $this->sendOtpViaEvolution($phone, $code);
    }

    public function provision(): void
    {
        if ($this->usesMeta()) {
            if ($this->status() === WhatsappProvisioningService::STATUS_PENDING) {
                $this->setStatus(WhatsappProvisioningService::STATUS_PENDING);
            }

            return;
        }

        $instanceName = $this->requireInstanceName();

        if (! $this->evolution->isConfigured()) {
            $this->markError('Evolution API não configurada no servidor.');

            return;
        }

        if ($this->evolution->isTestMode()) {
            $this->markConnected();

            return;
        }

        if ($this->status() === WhatsappProvisioningService::STATUS_CONNECTED) {
            return;
        }

        $this->setStatus(WhatsappProvisioningService::STATUS_PROVISIONING);
        PlatformSetting::set(self::KEY_LAST_ERROR, '');

        try {
            $this->evolution->createInstanceByName($instanceName, ['scope' => 'platform']);
            $this->setStatus(WhatsappProvisioningService::STATUS_AWAITING_QR);
            $this->syncConnection();
        } catch (Throwable $e) {
            Log::error('Platform Evolution provisioning failed', [
                'instance' => $instanceName,
                'error' => $e->getMessage(),
            ]);

            $this->markError($e, 'provision');
        }
    }

    public function syncConnection(): void
    {
        if ($this->usesMeta()) {
            return;
        }

        $instanceName = $this->instanceName();

        if (blank($instanceName) || ! $this->evolution->isConfigured()) {
            return;
        }

        $state = $this->evolution->fetchConnectionStateByName($instanceName);

        if ($state === null) {
            if ($this->status() === WhatsappProvisioningService::STATUS_CONNECTED) {
                $this->syncWhatsappNumberFromEvolution($instanceName);
            }

            return;
        }

        if ($this->evolution->isConnectedState($state)) {
            $this->syncWhatsappNumberFromEvolution($instanceName);
            $this->markConnected();
            $this->evolution->clearQrCache($instanceName);

            return;
        }

        if ($this->status() === WhatsappProvisioningService::STATUS_CONNECTED
            && $this->evolution->isDisconnectedState($state)) {
            $this->setStatus(WhatsappProvisioningService::STATUS_AWAITING_QR);
            PlatformSetting::set(self::KEY_CONNECTED_AT, '');
            PlatformSetting::set(self::KEY_NUMBER, '');
        }
    }

    public function disconnectForNumberChange(): void
    {
        if ($this->usesMeta()) {
            $this->disconnectMeta();

            return;
        }

        $instanceName = $this->requireInstanceName();

        if ($this->evolution->isTestMode()) {
            $this->setStatus(WhatsappProvisioningService::STATUS_AWAITING_QR);
            PlatformSetting::set(self::KEY_CONNECTED_AT, '');
            PlatformSetting::set(self::KEY_NUMBER, '');
            PlatformSetting::set(self::KEY_LAST_ERROR, '');
            $this->evolution->clearQrCache($instanceName);

            return;
        }

        if (! $this->evolution->isConfigured()) {
            throw new \RuntimeException('Evolution API não configurada no servidor.');
        }

        try {
            $this->evolution->logoutInstanceByName($instanceName, ['scope' => 'platform']);
        } catch (Throwable $e) {
            Log::warning('Platform Evolution logout failed during number change', [
                'instance' => $instanceName,
                'error' => $e->getMessage(),
            ]);
        }

        $this->setStatus(WhatsappProvisioningService::STATUS_AWAITING_QR);
        PlatformSetting::set(self::KEY_CONNECTED_AT, '');
        PlatformSetting::set(self::KEY_NUMBER, '');
        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    public function refreshQrCode(): ?array
    {
        if ($this->usesMeta()) {
            return null;
        }

        $instanceName = $this->requireInstanceName();

        if ($this->status() === WhatsappProvisioningService::STATUS_PENDING
            || $this->status() === WhatsappProvisioningService::STATUS_ERROR) {
            $this->provision();
        } else {
            $this->syncConnection();
        }

        if ($this->status() === WhatsappProvisioningService::STATUS_CONNECTED) {
            return null;
        }

        return $this->evolution->fetchQrCodeByName($instanceName, forceRefresh: true);
    }

    public function sendTestMessage(string $phone): void
    {
        $this->syncConnection();

        if (! $this->isConnected()) {
            throw new \RuntimeException('Conecte o WhatsApp da plataforma antes de enviar o teste.');
        }

        $normalizedDestination = $this->evolution->normalizePhonePublic($phone);
        $connectedNumber = PlatformSetting::get(self::KEY_NUMBER);

        if (filled($connectedNumber) && $normalizedDestination === $connectedNumber) {
            throw new \RuntimeException('Envie o teste para outro número WhatsApp, não para o chip conectado.');
        }

        $text = 'Teste PartiuMenu — esta instância envia códigos OTP de login para os clientes.';

        try {
            if ($this->usesMeta()) {
                $this->meta->sendTextToPhoneNumberId(
                    $this->metaPhoneNumberId(),
                    $this->metaAccessToken(),
                    $phone,
                    $text
                );
            } else {
                $instanceName = $this->requireInstanceName();

                if (! $this->evolution->isConfigured()) {
                    throw new \RuntimeException('Evolution API não configurada no servidor.');
                }

                if (! $this->evolution->isTestMode()
                    && ! $this->evolution->isInstanceReadyForMessaging($instanceName)) {
                    throw new \RuntimeException('A Evolution não confirmou a conexão do chip. Clique em Atualizar ou reconecte o QR.');
                }

                $this->evolution->sendText($instanceName, $phone, $text);
            }

            PlatformSetting::set(self::KEY_LAST_ERROR, '');
        } catch (Throwable $e) {
            $this->storeLastError($e, 'test_message');

            throw $e;
        }
    }

    public function saveConnectedNumber(string $phone): void
    {
        $normalized = $this->evolution->normalizePhonePublic($phone);

        if (strlen($normalized) < 12) {
            throw new \InvalidArgumentException('Informe o número do chip com DDD.');
        }

        PlatformSetting::set(self::KEY_NUMBER, $normalized);
        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    public function instanceName(): ?string
    {
        return $this->evolution->defaultInstanceName();
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

    private function sendOtpViaEvolution(string $phone, string $code): void
    {
        if (! $this->evolution->isConfigured()) {
            throw new \RuntimeException('Evolution API não configurada.');
        }

        $instanceName = $this->requireInstanceName();

        if (! $this->evolution->isTestMode()
            && ! $this->evolution->isInstanceReadyForMessaging($instanceName)) {
            throw new \RuntimeException('WhatsApp da plataforma não está pronto para enviar mensagens.');
        }

        $this->evolution->sendText(
            $instanceName,
            $phone,
            "Olá! Seu código de verificação é: *{$code}*."
        );

        PlatformSetting::set(self::KEY_LAST_ERROR, '');
    }

    private function disconnectEvolutionSilently(): void
    {
        if ($this->status() !== WhatsappProvisioningService::STATUS_CONNECTED) {
            return;
        }

        try {
            $this->disconnectForNumberChange();
        } catch (Throwable $e) {
            Log::warning('Platform Evolution disconnect during provider switch failed', [
                'error' => $e->getMessage(),
            ]);
        }
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

    private function requireInstanceName(): string
    {
        $instanceName = $this->instanceName();

        if (blank($instanceName)) {
            throw new \RuntimeException('EVOLUTION_INSTANCE_NAME não configurado no servidor.');
        }

        return $instanceName;
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

    private function syncWhatsappNumberFromEvolution(string $instanceName): void
    {
        $phone = $this->evolution->fetchInstanceOwnerPhoneByName($instanceName);

        if (filled($phone)) {
            PlatformSetting::set(self::KEY_NUMBER, $phone);
        }
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
