<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetaWhatsappService;
use App\Services\PlatformWhatsappService;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuperAdminPlatformWhatsappController extends Controller
{
    public function connection(PlatformWhatsappService $platformWhatsapp)
    {
        return response()->json($platformWhatsapp->connectionPayload(refreshQr: false));
    }

    public function provision(PlatformWhatsappService $platformWhatsapp)
    {
        try {
            $platformWhatsapp->provision();

            return response()->json([
                'message' => 'Instância da plataforma provisionada. Escaneie o QR Code para conectar.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: true, forceRefreshQr: true),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'platform_provision',
                $e,
                ['scope' => 'platform']
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao provisionar WhatsApp da plataforma.', $reported['error_ref']),
                400
            );
        }
    }

    public function syncConnection(PlatformWhatsappService $platformWhatsapp)
    {
        try {
            $platformWhatsapp->syncConnection();

            return response()->json([
                'message' => 'Status de conexão atualizado.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
            ]);
        } catch (Throwable $e) {
            if (IntegrationErrorReporter::isTransient($e)) {
                Log::warning('Platform WhatsApp sync transient failure', [
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Evolution demorou a responder. Mantendo o último status conhecido.',
                    'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
                    'transient' => true,
                ]);
            }

            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'platform_sync_connection',
                $e,
                ['scope' => 'platform']
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao sincronizar conexão WhatsApp.', $reported['error_ref']),
                400
            );
        }
    }

    public function qrcode(PlatformWhatsappService $platformWhatsapp)
    {
        try {
            $qrcode = $platformWhatsapp->refreshQrCode();

            return response()->json([
                'message' => 'QR Code atualizado.',
                'whatsapp' => array_merge(
                    $platformWhatsapp->connectionPayload(refreshQr: false),
                    ['qrcode' => $qrcode]
                ),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'platform_qrcode',
                $e,
                ['scope' => 'platform']
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao gerar QR Code.', $reported['error_ref']),
                400
            );
        }
    }

    public function disconnect(PlatformWhatsappService $platformWhatsapp)
    {
        try {
            $platformWhatsapp->disconnectForNumberChange();

            return response()->json([
                'message' => 'WhatsApp desconectado. Escaneie o QR Code com o novo chip.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: true, forceRefreshQr: true),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'platform_disconnect',
                $e,
                ['scope' => 'platform']
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao desconectar WhatsApp.', $reported['error_ref']),
                400
            );
        }
    }

    public function saveNumber(Request $request, PlatformWhatsappService $platformWhatsapp)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        try {
            $platformWhatsapp->saveConnectedNumber($validated['phone']);

            return response()->json([
                'message' => 'Número do chip salvo.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function sendTestMessage(Request $request, PlatformWhatsappService $platformWhatsapp)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        try {
            $platformWhatsapp->sendTestMessage($validated['phone']);

            return response()->json([
                'message' => app(PlatformWhatsappService::class)->usesMeta()
                    ? (app(MetaWhatsappService::class)->isTestMode()
                        ? 'Mensagem de teste registrada no log do servidor.'
                        : 'Mensagem de teste enviada.')
                    : (app(\App\Services\EvolutionService::class)->isTestMode()
                        ? 'Mensagem de teste registrada no log do servidor.'
                        : 'Mensagem de teste enviada.'),
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
            ]);
        } catch (Throwable $e) {
            $message = IntegrationErrorReporter::sanitize($e->getMessage());

            if (IntegrationErrorReporter::isTransient($e)) {
                $message = 'Evolution demorou a responder. Aguarde alguns segundos e tente novamente.';
            }

            return response()->json([
                'message' => $message !== 'Erro interno. Tente novamente ou contate o suporte.'
                    ? $message
                    : 'Falha ao enviar mensagem de teste.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
            ], IntegrationErrorReporter::isTransient($e) ? 503 : 422);
        }
    }

    public function updateProvider(Request $request, PlatformWhatsappService $platformWhatsapp)
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:evolution,meta'],
        ]);

        try {
            $platformWhatsapp->setProvider($validated['provider']);

            return response()->json([
                'message' => $validated['provider'] === PlatformWhatsappService::PROVIDER_META
                    ? 'Modo oficial (Meta) selecionado para OTP.'
                    : 'Modo rápido (QR Code) selecionado para OTP.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: true),
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

    public function completeMetaSignup(Request $request, PlatformWhatsappService $platformWhatsapp)
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'max:255'],
            'phone_number_id' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'string', 'size:6'],
            'display_phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $platformWhatsapp->completeMetaSignup($validated);

            return response()->json([
                'message' => 'WhatsApp oficial da plataforma conectado.',
                'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'whatsapp',
                'platform_meta_signup',
                $e,
                ['scope' => 'platform']
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao conectar WhatsApp oficial.', $reported['error_ref']),
                400
            );
        }
    }

    public function disconnectMeta(PlatformWhatsappService $platformWhatsapp)
    {
        $platformWhatsapp->disconnectMeta();

        return response()->json([
            'message' => 'WhatsApp oficial desconectado.',
            'whatsapp' => $platformWhatsapp->connectionPayload(refreshQr: false),
        ]);
    }
}
