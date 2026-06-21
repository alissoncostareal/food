<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function provision(PlatformWhatsappService $platformWhatsapp)
    {
        try {
            $platformWhatsapp->provision();

            return response()->json([
                'message' => 'Instância da plataforma provisionada. Escaneie o QR Code para conectar.',
                'whatsapp' => $platformWhatsapp->connectionPayload(),
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
                'whatsapp' => $platformWhatsapp->connectionPayload(),
            ]);
        } catch (Throwable $e) {
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
            $payload = $platformWhatsapp->connectionPayload();

            if ($qrcode) {
                $payload['qrcode'] = $qrcode;
            }

            return response()->json([
                'message' => 'QR Code atualizado.',
                'whatsapp' => $payload,
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
            $payload = $platformWhatsapp->connectionPayload();

            if (empty($payload['qrcode']) && filled($payload['instance_name'])) {
                $payload['qrcode'] = app(\App\Services\EvolutionService::class)
                    ->fetchQrCodeByName($payload['instance_name']);
            }

            return response()->json([
                'message' => 'WhatsApp desconectado. Escaneie o QR Code com o novo chip.',
                'whatsapp' => $payload,
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

    public function sendTestMessage(Request $request, PlatformWhatsappService $platformWhatsapp)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        try {
            $platformWhatsapp->sendTestMessage($validated['phone']);

            return response()->json([
                'message' => app(\App\Services\EvolutionService::class)->isTestMode()
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
}
