<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Services\IfoodCatalogImporter;
use App\Services\IfoodFinancialService;
use App\Services\IfoodOrderHandler;
use App\Services\IfoodSandboxCatalogSeeder;
use App\Services\IfoodService;
use Illuminate\Http\Request;
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
            $store->fill([
                'ifood_integration_status' => 'error',
                'ifood_last_error' => $e->getMessage(),
            ])->save();

            return response()->json([
                'message' => 'Erro ao gerar código iFood.',
                'details' => config('app.debug') ? $e->getMessage() : $e->getMessage(),
            ], 400);
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
            $store->fill([
                'ifood_integration_status' => 'error',
                'ifood_last_error' => $e->getMessage(),
            ])->save();

            return response()->json([
                'message' => $e->getMessage() ?: 'Erro ao validar código de autorização iFood.',
            ], 400);
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
            return response()->json([
                'message' => 'Erro ao listar lojas autorizadas no iFood.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
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
            $store->fill([
                'ifood_integration_status' => 'error',
                'ifood_last_error' => $this->stringifyError($e),
            ])->save();

            return response()->json([
                'message' => 'Não foi possível validar a conexão com o iFood.',
                'details' => $e->getMessage(),
                'store' => $store->fresh()->ifoodConnectionPayload(),
            ], 400);
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
            return response()->json([
                'message' => 'Erro ao importar catálogo do iFood.',
                'details' => $e->getMessage(),
            ], 400);
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
            return response()->json([
                'message' => 'Erro ao consultar vendas do iFood.',
                'details' => $e->getMessage(),
            ], 400);
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
            return response()->json([
                'message' => 'Erro ao criar catálogo de teste no iFood.',
                'details' => $e->getMessage(),
            ], 400);
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

    private function stringifyError(Throwable $e): string
    {
        $message = $e->getMessage();

        if (is_array($message)) {
            return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ?: 'Erro desconhecido.';
        }

        return (string) $message;
    }
}
