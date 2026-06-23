<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Jobs\ProcessWhatsappInboundMessage;
use App\Models\Store;
use App\Services\EvolutionService;
use App\Services\MetaWhatsappPayload;
use App\Services\MetaWhatsappProvisioningService;
use App\Services\MetaWhatsappService;
use App\Services\StoreWhatsappConnectionService;
use App\Services\StoreWhatsappMessenger;
use App\Services\WhatsappAiAssistant;
use App\Services\WhatsappEvolutionPayload;
use App\Services\WhatsappInboundHandler;
use App\Services\WhatsappOrderMessageTemplates;
use App\Services\WhatsappProvisioningService;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappIntegrationController extends Controller
{
    use ResolvesMerchantStore;

    public function status(EvolutionService $evolution, MetaWhatsappService $meta)
    {
        return response()->json([
            'evolution' => $evolution->configurationStatus(),
            'meta' => $meta->configurationStatus(),
        ]);
    }

    public function connection(StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        try {
            return response()->json($connection->connectionPayload($store, refreshQr: false));
        } catch (Throwable $e) {
            Log::warning('WhatsApp connection payload failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(
                array_merge(
                    $connection->connectionPayload($store->fresh(['plan']), refreshQr: false),
                    ['transient' => IntegrationErrorReporter::isTransient($e)]
                )
            );
        }
    }

    public function updateProvider(Request $request, StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'provider' => ['required', 'in:evolution,meta'],
        ]);

        try {
            $store = $connection->setProvider($store, $validated['provider']);

            return response()->json([
                'message' => $validated['provider'] === Store::WHATSAPP_PROVIDER_META
                    ? 'Modo oficial (Meta) selecionado. Conecte o número da loja.'
                    : 'Modo rápido (QR Code) selecionado. Escaneie o QR para conectar.',
                'whatsapp' => $connection->connectionPayload($store),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Erro ao alterar modo de conexão.',
            ], 422);
        }
    }

    public function metaConfig(MetaWhatsappService $meta)
    {
        if (! $meta->isEmbeddedSignupReady()) {
            return response()->json([
                'message' => 'WhatsApp Meta não configurado no servidor.',
                'meta' => $meta->configurationStatus(),
            ], 422);
        }

        return response()->json([
            'meta' => array_merge($meta->configurationStatus(), [
                'embedded_signup' => $meta->embeddedSignupConfig(),
            ]),
        ]);
    }

    public function completeMetaSignup(Request $request, MetaWhatsappProvisioningService $metaProvisioning, StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'max:255'],
            'phone_number_id' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'string', 'size:6'],
            'display_phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $store = $connection->setProvider($store, Store::WHATSAPP_PROVIDER_META);
            $store = $metaProvisioning->completeEmbeddedSignup($store, $validated);

            return response()->json([
                'message' => $store->meta_whatsapp_status === MetaWhatsappProvisioningService::STATUS_CONNECTED
                    ? 'WhatsApp oficial conectado com sucesso.'
                    : 'Não foi possível concluir a conexão com a Meta.',
                'whatsapp' => $connection->connectionPayload($store),
            ], $store->meta_whatsapp_status === MetaWhatsappProvisioningService::STATUS_CONNECTED ? 200 : 422);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'meta_signup',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao conectar WhatsApp oficial.', $reported['error_ref']),
                400
            );
        }
    }

    public function disconnectMeta(MetaWhatsappProvisioningService $metaProvisioning, StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $store = $metaProvisioning->disconnect($store);

        return response()->json([
            'message' => 'WhatsApp oficial desconectado.',
            'whatsapp' => $connection->connectionPayload($store),
        ]);
    }

    public function provision(WhatsappProvisioningService $provisioning, StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        if ($store->usesMetaWhatsapp()) {
            return response()->json([
                'message' => 'Use a conexão oficial (Meta) neste modo.',
                'whatsapp' => $connection->connectionPayload($store),
            ], 422);
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

    public function syncConnection(WhatsappProvisioningService $provisioning, StoreWhatsappConnectionService $connection)
    {
        $store = $this->merchantStore();

        if ($store->usesMetaWhatsapp()) {
            return response()->json([
                'message' => 'Status de conexão atualizado.',
                'whatsapp' => $connection->connectionPayload($store, refreshQr: false),
            ]);
        }

        try {
            $store = $provisioning->syncConnection($store);

            return response()->json([
                'message' => 'Status de conexão atualizado.',
                'whatsapp' => $connection->connectionPayload($store, refreshQr: false),
            ]);
        } catch (Throwable $e) {
            if (IntegrationErrorReporter::isTransient($e)) {
                Log::warning('WhatsApp sync transient failure', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Evolution demorou a responder. Mantendo o último status conhecido.',
                    'whatsapp' => $connection->connectionPayload($store->fresh(['plan']), refreshQr: false),
                    'transient' => true,
                ]);
            }

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

    public function sendTestMessage(
        Request $request,
        EvolutionService $evolution,
        WhatsappProvisioningService $provisioning,
        StoreWhatsappConnectionService $connection,
        StoreWhatsappMessenger $messenger,
    ) {
        $store = $this->merchantStore();

        if (! $store->canUseFeature('whatsapp_auto')) {
            return response()->json([
                'message' => 'WhatsApp automático disponível a partir do plano Pro.',
            ], 403);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        if (! $connection->isConnected($store)) {
            return response()->json([
                'message' => 'Conecte o WhatsApp antes de enviar o teste.',
            ], 422);
        }

        if (! $messenger->canSend($store)) {
            return response()->json([
                'message' => $store->usesMetaWhatsapp()
                    ? 'WhatsApp Meta não configurado no servidor.'
                    : 'Evolution API não configurada no servidor.',
            ], 422);
        }

        try {
            $text = 'Teste PartiuMenu — seu WhatsApp está conectado e pronto para enviar status de pedidos aos clientes.';

            $messenger->sendText($store, $validated['phone'], $text);

            return response()->json([
                'message' => ($store->usesMetaWhatsapp() && app(MetaWhatsappService::class)->isTestMode())
                    || ($store->usesEvolutionWhatsapp() && $evolution->isTestMode())
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

    public function metaWebhook(
        Request $request,
        MetaWhatsappPayload $payloadParser,
        WhatsappInboundHandler $inboundHandler
    ) {
        if ($request->isMethod('GET')) {
            $mode = (string) $request->query('hub_mode');
            $token = (string) $request->query('hub_verify_token');
            $challenge = (string) $request->query('hub_challenge');
            $expected = (string) config('services.meta_whatsapp.webhook_verify_token');

            if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->all();

        foreach ($payloadParser->extractInboundMessages($payload) as $message) {
            $phoneNumberId = trim((string) ($message['phone_number_id'] ?? ''));

            if ($phoneNumberId === '') {
                continue;
            }

            $store = Store::query()
                ->where('meta_phone_number_id', $phoneNumberId)
                ->where('whatsapp_provider', Store::WHATSAPP_PROVIDER_META)
                ->first();

            if (! $store) {
                Log::warning('Meta WhatsApp webhook: store not found for phone_number_id', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                continue;
            }

            $inboundHandler->handleInboundMessage($store, $message['phone'], $message['text']);
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
