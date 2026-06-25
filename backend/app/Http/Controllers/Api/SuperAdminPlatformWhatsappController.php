<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetaWhatsappService;
use App\Services\PlatformWhatsappService;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Request;
use Throwable;

class SuperAdminPlatformWhatsappController extends Controller
{
    public function connection(PlatformWhatsappService $platformWhatsapp)
    {
        return response()->json($platformWhatsapp->connectionPayload());
    }

    public function syncConnection(PlatformWhatsappService $platformWhatsapp)
    {
        return response()->json([
            'message' => 'Status atualizado.',
            'whatsapp' => $platformWhatsapp->connectionPayload(),
        ]);
    }

    public function disconnect(PlatformWhatsappService $platformWhatsapp)
    {
        $platformWhatsapp->disconnectMeta();

        return response()->json([
            'message' => 'WhatsApp oficial desconectado.',
            'whatsapp' => $platformWhatsapp->connectionPayload(),
        ]);
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
                'whatsapp' => $platformWhatsapp->connectionPayload(),
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
                'message' => app(MetaWhatsappService::class)->isTestMode()
                    ? 'Mensagem de teste registrada no log do servidor.'
                    : 'Mensagem de teste enviada.',
                'whatsapp' => $platformWhatsapp->connectionPayload(),
            ]);
        } catch (Throwable $e) {
            $message = IntegrationErrorReporter::sanitize($e->getMessage());

            return response()->json([
                'message' => $message !== 'Erro interno. Tente novamente ou contate o suporte.'
                    ? $message
                    : 'Falha ao enviar mensagem de teste.',
                'whatsapp' => $platformWhatsapp->connectionPayload(),
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
                'whatsapp' => $platformWhatsapp->connectionPayload(),
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
            'whatsapp' => $platformWhatsapp->connectionPayload(),
        ]);
    }
}
