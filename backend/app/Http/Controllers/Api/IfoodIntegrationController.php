<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Services\IfoodCatalogImporter;
use App\Services\IfoodFinancialService;
use App\Services\IfoodOrderHandler;
use App\Services\IfoodSandboxCatalogSeeder;
use App\Services\IfoodService;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class IfoodIntegrationController extends Controller
{
    use ResolvesMerchantStore;

    public function status(IfoodService $ifood)
    {
        try {
            return response()->json([
                'ifood' => $ifood->configurationStatus(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao verificar integração iFood.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function connection(IfoodService $ifood)
    {
        $store = $this->merchantStore();

        try {
            $payload = $ifood->storeConnectionStatus($store);

            if ($ifood->isSandbox()) {
                try {
                    $payload['sandbox_merchants'] = $ifood->listCentralizedSandboxMerchants();
                } catch (Throwable $sandboxError) {
                    Log::warning('iFood sandbox merchants unavailable', [
                        'store_id' => $store->id,
                        'error' => $sandboxError->getMessage(),
                    ]);
                    $payload['sandbox_merchants'] = [];
                    $payload['sandbox_merchants_error'] = config('app.debug')
                        ? $sandboxError->getMessage()
                        : null;
                }
            }

            return response()->json($payload);
        } catch (Throwable $e) {
            Log::warning('iFood connection status failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erro ao carregar conexão iFood.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function updateConnection(Request $request, IfoodService $ifood)
    {
        $store = $this->merchantStore();

        $validated = $request->validate([
            'merchant_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $store = $ifood->saveStoreMerchantId($store, $validated['merchant_id']);

            return response()->json([
                'message' => 'Merchant ID salvo. Conclua a autorização e teste a conexão.',
                'store' => $store->ifoodConnectionPayload(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Erro ao salvar Merchant ID.',
            ], 422);
        }
    }

    public function createUserCode(IfoodService $ifood)
    {
        $store = $this->merchantStore();

        try {
            $userCode = $ifood->createUserCode($store);

            return response()->json([
                'message' => 'Código gerado. Autorize no portal iFood e cole o código de autorização abaixo.',
                'oauth' => $userCode,
                'store' => $store->fresh()->ifoodConnectionPayload(),
            ]);
        } catch (Throwable $e) {
            $stored = $this->recordIfoodError($store, $e, 'create_user_code');

            return $this->ifoodErrorResponse('Erro ao gerar código iFood.', $stored, $store);
        }
    }

    public function exchangeAuthorizationCode(Request $request, IfoodService $ifood)
    {
        $store = $this->merchantStore();

        $validated = $request->validate([
            'authorization_code' => ['required', 'string', 'max:32'],
        ]);

        try {
            $store = $ifood->exchangeAuthorizationCode($store, $validated['authorization_code']);
            $merchants = $ifood->listAuthorizedMerchants($store);

            if (count($merchants) === 1 && blank($store->ifood_merchant_id)) {
                $store = $ifood->saveStoreMerchantId($store, (string) $merchants[0]['id']);
            }

            return response()->json([
                'message' => 'Autorização iFood concluída. Confirme o Merchant ID e teste a conexão.',
                'merchants' => $merchants,
                'store' => $store->fresh()->ifoodConnectionPayload(),
            ]);
        } catch (Throwable $e) {
            $stored = $this->recordIfoodError($store, $e, 'exchange_authorization_code');
            $parsed = IntegrationErrorReporter::parseStored($stored);

            return response()->json(array_merge(
                IntegrationErrorReporter::response(
                    IntegrationErrorReporter::sanitize($e->getMessage()) ?: 'Erro ao validar código de autorização iFood.',
                    $parsed['error_ref']
                ),
                ['store' => $store->fresh()->ifoodConnectionPayload()]
            ), 400);
        }
    }

    public function authorizedMerchants(IfoodService $ifood)
    {
        $store = $this->merchantStore();

        try {
            return response()->json([
                'merchants' => $ifood->listAuthorizedMerchants($store),
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'ifood',
                'authorized_merchants',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao listar lojas autorizadas no iFood.', $reported['error_ref']),
                400
            );
        }
    }

    public function testConnection(IfoodService $ifood)
    {
        $store = $this->merchantStore();

        try {
            $result = $ifood->testStoreConnection($store);

            return response()->json([
                'message' => 'Loja conectada ao iFood com sucesso.',
                'connection' => $result,
                'store' => $store->fresh()->ifoodConnectionPayload(),
            ]);
        } catch (Throwable $e) {
            $stored = $this->recordIfoodError($store, $e, 'test_connection');

            return $this->ifoodErrorResponse(
                'Não foi possível validar a conexão com o iFood.',
                $stored,
                $store
            );
        }
    }

    public function disconnect(IfoodService $ifood)
    {
        $store = $this->merchantStore();
        $store = $ifood->disconnectStore($store);

        return response()->json([
            'message' => 'Integração iFood desconectada.',
            'store' => $store->ifoodConnectionPayload(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $store = $this->merchantStore();

        $validated = $request->validate([
            'auto_confirm' => ['required', 'boolean'],
        ]);

        $store->update([
            'ifood_auto_confirm' => $validated['auto_confirm'],
        ]);

        return response()->json([
            'message' => 'Preferências iFood salvas.',
            'store' => $store->fresh()->ifoodConnectionPayload(),
        ]);
    }

    public function importCatalog(IfoodCatalogImporter $importer)
    {
        $store = $this->merchantStore();

        try {
            $stats = $importer->import($store);

            return response()->json([
                'message' => 'Catálogo iFood importado com sucesso.',
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'ifood',
                'import_catalog',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao importar catálogo do iFood.', $reported['error_ref']),
                400
            );
        }
    }

    public function sales(Request $request, IfoodFinancialService $financial)
    {
        $store = $this->merchantStore();

        $validated = $request->validate([
            'begin_sales_date' => ['required', 'date'],
            'end_sales_date' => ['required', 'date', 'after_or_equal:begin_sales_date'],
        ]);

        try {
            $result = $financial->fetchSalesSummary(
                $store,
                $validated['begin_sales_date'],
                $validated['end_sales_date']
            );

            return response()->json([
                'message' => 'Vendas iFood carregadas.',
                'sales' => $result,
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'ifood',
                'sales',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao consultar vendas do iFood.', $reported['error_ref']),
                400
            );
        }
    }

    public function seedSandboxCatalog(IfoodSandboxCatalogSeeder $seeder, IfoodService $ifood)
    {
        $store = $this->merchantStore();

        if (! $ifood->isSandbox()) {
            return response()->json(['message' => 'Disponível apenas em sandbox.'], 422);
        }

        try {
            $result = $seeder->seed($store);

            return response()->json([
                'message' => $result['message'],
                'seed' => $result,
            ]);
        } catch (Throwable $e) {
            $reported = IntegrationErrorReporter::report(
                'ifood',
                'seed_sandbox',
                $e,
                ['store_id' => $store->id]
            );

            return response()->json(
                IntegrationErrorReporter::response('Erro ao criar catálogo de teste no iFood.', $reported['error_ref']),
                400
            );
        }
    }

    public function webhook(Request $request, IfoodService $ifood, IfoodOrderHandler $orderHandler)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-IFood-Signature');

        if (! $ifood->validateWebhookSignature($rawBody, $signature)) {
            Log::warning('iFood webhook rejeitado: assinatura inválida ou ausente', [
                'has_signature' => filled($signature),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Assinatura inválida.',
            ], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Payload inválido.',
            ], 422);
        }

        $merchantId = data_get($payload, 'merchantId')
            ?: data_get($payload, 'merchant.id')
            ?: (is_array(data_get($payload, 'merchantIds')) ? data_get($payload, 'merchantIds.0') : null);

        $store = $ifood->findStoreByMerchantId($merchantId);
        $code = strtoupper((string) data_get($payload, 'code', ''));

        Log::info('iFood webhook recebido', [
            'event_id' => data_get($payload, 'id'),
            'code' => $code,
            'order_id' => data_get($payload, 'orderId'),
            'merchant_id' => $merchantId,
            'store_id' => $store?->id,
        ]);

        if ($code === 'KEEPALIVE') {
            if ($ifood->usesPresenceByMerchant()) {
                $merchantIds = data_get($payload, 'merchantIds', []);

                return response()->json([
                    'merchantIds' => is_array($merchantIds) ? $merchantIds : [],
                ], 202);
            }

            return response()->json([], 202);
        }

        if (! $store) {
            Log::warning('iFood webhook sem loja correspondente', [
                'merchant_id' => $merchantId,
                'code' => $code,
            ]);

            return response()->json(['received' => true], 202);
        }

        try {
            $result = $orderHandler->handle($store, $payload);

            return response()->json([
                'received' => true,
                'result' => $result,
            ], 202);
        } catch (Throwable $e) {
            Log::error('iFood webhook handler error', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'received' => true,
                'error' => config('app.debug') ? $e->getMessage() : 'processing_failed',
            ], 202);
        }
    }

    private function recordIfoodError($store, Throwable $e, string $action): string
    {
        $stored = IntegrationErrorReporter::storeMessage(
            'ifood',
            $action,
            $e,
            ['store_id' => $store->id]
        );

        $store->fill([
            'ifood_integration_status' => 'error',
            'ifood_last_error' => $stored,
        ])->save();

        return $stored;
    }

    private function ifoodErrorResponse(string $message, string $stored, $store = null, int $status = 400): JsonResponse
    {
        $parsed = IntegrationErrorReporter::parseStored($stored);

        $payload = IntegrationErrorReporter::response($message, $parsed['error_ref']);

        if ($store) {
            $payload['store'] = $store->fresh()->ifoodConnectionPayload();
        }

        return response()->json($payload, $status);
    }
}
