<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\IntegrationErrorReporter;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformWhatsappService
{
    private const KEY_STATUS = 'platform_whatsapp_status';

    private const KEY_CONNECTED_AT = 'platform_whatsapp_connected_at';

    private const KEY_NUMBER = 'platform_whatsapp_number';

    private const KEY_LAST_ERROR = 'platform_whatsapp_last_error';

    public function __construct(
        private readonly EvolutionService $evolution
    ) {}

    public function instanceName(): ?string
    {
        return $this->evolution->defaultInstanceName();
    }

    public function connectionPayload(bool $refreshQr = true, bool $forceRefreshQr = false): array
    {
        $error = IntegrationErrorReporter::parseStored(
            PlatformSetting::get(self::KEY_LAST_ERROR)
        );

        $status = PlatformSetting::get(self::KEY_STATUS, WhatsappProvisioningService::STATUS_PENDING);
        $connectedAt = PlatformSetting::get(self::KEY_CONNECTED_AT);
        $instanceName = $this->instanceName();

        $payload = [
            'scope' => 'platform',
            'purpose' => 'otp',
            'purpose_label' => 'Login dos clientes (código OTP)',
            'instance_name' => $instanceName,
            'instance_name_missing' => blank($instanceName),
            'status' => $status ?: WhatsappProvisioningService::STATUS_PENDING,
            'connected_at' => filled($connectedAt) ? $connectedAt : null,
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'whatsapp_number' => PlatformSetting::get(self::KEY_NUMBER),
            'evolution' => $this->evolution->configurationStatus(),
            'test_mode' => $this->evolution->isTestMode(),
        ];

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

        return $payload;
    }

    public function provision(): void
    {
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
        $instanceName = $this->instanceName();

        if (blank($instanceName) || ! $this->evolution->isConfigured()) {
            return;
        }

        $state = $this->evolution->fetchConnectionStateByName($instanceName);

        if ($this->evolution->isConnectedState($state)) {
            $this->syncWhatsappNumberFromEvolution($instanceName);
            $this->markConnected();
            $this->evolution->clearQrCache($instanceName);

            return;
        }

        if ($this->status() === WhatsappProvisioningService::STATUS_CONNECTED) {
            $this->setStatus(WhatsappProvisioningService::STATUS_AWAITING_QR);
            PlatformSetting::set(self::KEY_CONNECTED_AT, '');
            PlatformSetting::set(self::KEY_NUMBER, '');
        }
    }

    public function disconnectForNumberChange(): void
    {
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
        $instanceName = $this->requireInstanceName();

        if ($this->status() !== WhatsappProvisioningService::STATUS_CONNECTED
            && ! $this->evolution->isTestMode()) {
            throw new \RuntimeException('Conecte o WhatsApp da plataforma antes de enviar o teste.');
        }

        if (! $this->evolution->isConfigured()) {
            throw new \RuntimeException('Evolution API não configurada no servidor.');
        }

        $text = 'Teste PartiuMenu — esta instância envia códigos OTP de login para os clientes.';

        $this->evolution->sendText($instanceName, $phone, $text);
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
