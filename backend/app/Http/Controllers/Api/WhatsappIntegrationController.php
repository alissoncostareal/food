<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Jobs\ProcessWhatsappInboundMessage;
use App\Models\Store;
use App\Services\EvolutionService;
use App\Services\WhatsappAiAssistant;
use App\Services\WhatsappEvolutionPayload;
use App\Services\WhatsappOrderMessageTemplates;
use App\Services\WhatsappProvisioningService;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappIntegrationController extends Controller
{
    use ResolvesMerchantStore;

    public function status(EvolutionService $evolution)
    {
        return response()->json([
            'evolution' => $evolution->configurationStatus(),
        ]);
    }

    public function connection(WhatsappProvisioningService $provisioning)
    {
        $store = $this->merchantStore();

        return response()->json($provisioning->connectionPayload($store));
    }

    public function provision(WhatsappProvisioningService $provisioning)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        try {
            $store = $provisioning->provision($store);

            return response()->json([
                'message' => 'Instância WhatsApp provisionada. Escaneie o QR Code para conectar.',
                'whatsapp' => $provisioning->connectionPayload($store),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'provision',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao provisionar WhatsApp.', $reported['error_ref']),
                400
            );
        }
    }

    public function syncConnection(WhatsappProvisioningService $provisioning)
    {
        $store = $this->merchantStore();

        try {
            $store = $provisioning->syncConnection($store);

            return response()->json([
                'message' => 'Status de conexão atualizado.',
                'whatsapp' => $provisioning->connectionPayload($store, refreshQr: false),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'sync_connection',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao sincronizar conexão WhatsApp.', $reported['error_ref']),
                400
            );
        }
    }

    public function qrcode(EvolutionService $evolution, WhatsappProvisioningService $provisioning)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        try {
            if (blank($store->evolution_instance_name)
                || in_array($store->evolution_status, [
                    WhatsappProvisioningService::STATUS_PENDING,
                    WhatsappProvisioningService::STATUS_ERROR,
                    WhatsappProvisioningService::STATUS_DISABLED,
                ], true)) {
                $store = $provisioning->provision($store);
            }

            $store = $provisioning->syncConnection($store);
            $payload = $provisioning->connectionPayload($store);

            if (empty($payload['qrcode'])) {
                $payload['qrcode'] = $evolution->fetchQrCode($store);
            }

            return response()->json([
                'message' => 'QR Code atualizado.',
                'whatsapp' => $payload,
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'qrcode',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao gerar QR Code.', $reported['error_ref']),
                400
            );
        }
    }

    public function disconnect(WhatsappProvisioningService $provisioning, EvolutionService $evolution)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        try {
            $store = $provisioning->disconnectForNumberChange($store);
            $payload = $provisioning->connectionPayload($store);

            if (empty($payload['qrcode'])) {
                $payload['qrcode'] = $evolution->fetchQrCode($store);
            }

            return response()->json([
                'message' => 'WhatsApp desconectado. Escaneie o QR Code com o novo número.',
                'whatsapp' => $payload,
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'disconnect',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao desconectar WhatsApp.', $reported['error_ref']),
                400
            );
        }
    }

    public function sendTestMessage(Request $request, EvolutionService $evolution, WhatsappProvisioningService $provisioning)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $connected = $store->evolution_status === WhatsappProvisioningService::STATUS_CONNECTED;

        if (! $connected && ! $evolution->isTestMode()) {
            return response()->json([
                'message' => 'Conecte o WhatsApp antes de enviar o teste.',
            ], 422);
        }

        if (! $evolution->isConfigured()) {
            return response()->json([
                'message' => 'Evolution API não configurada no servidor.',
            ], 422);
        }

        try {
            $text = 'Teste PartiuMenu — seu WhatsApp está conectado e pronto para enviar status de pedidos aos clientes.';

            $evolution->sendTextForStore($store, $validated['phone'], $text);

            return response()->json([
                'message' => $evolution->isTestMode()
                    ? 'Mensagem de teste registrada no log do servidor.'
                    : 'Mensagem de teste enviada.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Falha ao enviar mensagem de teste.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function botSettings()
    {
        $store = $this->merchantStore();

        return response()->json([
            'settings' => $this->botSettingsPayload($store),
        ]);
    }

    public function updateBotSettings(Request $request, EvolutionService $evolution)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'whatsapp_bot_enabled' => ['sometimes', 'boolean'],
            'whatsapp_ai_enabled' => ['sometimes', 'boolean'],
            'whatsapp_bot_welcome' => ['nullable', 'string', 'max:2000'],
            'whatsapp_ai_faq' => ['nullable', 'string', 'max:4000'],
        ]);

        if (array_key_exists('whatsapp_ai_enabled', $validated) && ! $store->canUseFeature('whatsapp_ai')) {
            return response()->json([
                'message' => 'IA no WhatsApp disponível no plano Premium.',
            ], 403);
        }

        $faq = array_key_exists('whatsapp_ai_faq', $validated)
            ? trim((string) $validated['whatsapp_ai_faq'])
            : trim((string) $store->whatsapp_ai_faq);

        $aiEnabled = array_key_exists('whatsapp_ai_enabled', $validated)
            ? (bool) $validated['whatsapp_ai_enabled']
            : (bool) $store->whatsapp_ai_enabled;

        if ($aiEnabled && ! $store->canUseFeature('whatsapp_ai')) {
            return response()->json([
                'message' => 'IA no WhatsApp disponível no plano Premium.',
            ], 403);
        }

        if ($aiEnabled && mb_strlen($faq) < (int) config('whatsapp.ai_faq_min_chars', 20)) {
            return response()->json([
                'message' => 'Preencha o FAQ da loja (mínimo 20 caracteres) antes de ativar a IA.',
            ], 422);
        }

        if (mb_strlen($faq) < (int) config('whatsapp.ai_faq_min_chars', 20)) {
            $validated['whatsapp_ai_enabled'] = false;
        }

        $store->update($validated);

        if ($store->canUseFeature('whatsapp_bot') && $store->whatsapp_bot_enabled) {
            try {
                $evolution->configureWebhook($store->fresh());
            } catch (Throwable $e) {
                Log::warning('Failed to configure Evolution webhook after bot settings update', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Configurações do bot salvas.',
            'settings' => $this->botSettingsPayload($store->fresh()),
        ]);
    }

    public function messages()
    {
        $store = $this->merchantStore();

        return response()->json([
            'labels' => WhatsappOrderMessageTemplates::labels(),
            'defaults' => WhatsappOrderMessageTemplates::defaults(),
            'messages' => $store->whatsapp_order_messages ?? [],
            'placeholders' => WhatsappOrderMessageTemplates::PLACEHOLDERS,
        ]);
    }

    public function updateMessages(Request $request)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'messages' => ['required', 'array'],
            'messages.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $allowed = array_keys(WhatsappOrderMessageTemplates::labels());
        $defaults = WhatsappOrderMessageTemplates::defaults();
        $filtered = collect($validated['messages'])
            ->only($allowed)
            ->map(fn ($value) => is_string($value) ? trim($value) : '')
            ->filter(fn ($value, $key) => filled($value) && $value !== ($defaults[$key] ?? ''))
            ->all();

        $store->update(['whatsapp_order_messages' => $filtered]);

        return response()->json([
            'message' => 'Mensagens salvas.',
            'messages' => $store->fresh()->whatsapp_order_messages ?? [],
        ]);
    }

    public function webhook(
        Request $request,
        Store $store,
        WhatsappProvisioningService $provisioning,
        WhatsappEvolutionPayload $payloadParser
    ) {
        $secret = config('services.evolution.webhook_secret');

        if (filled($secret) && $request->header('x-evolution-secret') !== $secret) {
            Log::warning('Evolution webhook rejeitado: secret inválido', [
                'store_id' => $store->id,
                'slug' => $store->slug,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = strtolower((string) (
            $request->input('event')
            ?? data_get($request->input('data'), 'event')
            ?? $request->input('type')
        ));

        Log::info('Evolution webhook recebido', [
            'store_id' => $store->id,
            'slug' => $store->slug,
            'event' => $event,
        ]);

        if (str_contains($event, 'connection')) {
            $provisioning->syncConnection($store);
        }

        $payload = $request->all();
        $hasInbound = $payloadParser->isInboundMessageEvent($event)
            || $payloadParser->extractInboundMessages($payload) !== [];

        if ($hasInbound) {
            ProcessWhatsappInboundMessage::dispatchSync($store->id, $payload, $event);
        }

        return response()->json(['ok' => true]);
    }

    private function botSettingsPayload(Store $store): array
    {
        $store->loadMissing('plan');
        $assistant = app(WhatsappAiAssistant::class);

        return [
            'whatsapp_bot_enabled' => (bool) $store->whatsapp_bot_enabled,
            'whatsapp_ai_enabled' => (bool) $store->whatsapp_ai_enabled,
            'whatsapp_bot_welcome' => $store->whatsapp_bot_welcome,
            'whatsapp_ai_faq' => $store->whatsapp_ai_faq,
            'features' => [
                'bot' => $store->canUseFeature('whatsapp_bot'),
                'ai' => $store->canUseFeature('whatsapp_ai'),
            ],
            'ai_provider' => $assistant->provider(),
            'ai_provider_label' => $assistant->providerLabel(),
            'ai_configured' => $assistant->isConfigured(),
            'ai_faq_filled' => $store->whatsappAiFaqFilled(),
            'ai_faq_min_chars' => (int) config('whatsapp.ai_faq_min_chars', 20),
            'ai_active' => $assistant->canReply($store),
            'openai_configured' => $assistant->isConfigured(),
        ];
    }
}
