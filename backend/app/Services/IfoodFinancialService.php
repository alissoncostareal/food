<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IfoodFinancialService
{
    public function __construct(
        private readonly IfoodService $ifood
    ) {}

    public function fetchSalesSummary(Store $store, string $beginSalesDate, string $endSalesDate): array
    {
        if (! $store->isIfoodConnected()) {
            throw new RuntimeException('Conecte e valide sua loja iFood antes de consultar vendas.');
        }

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;
        $page = 1;
        $size = 100;
        $allSales = [];
        $pageCount = 1;

        do {
            $payload = $this->requestSalesPage(
                $token,
                $merchantId,
                $beginSalesDate,
                $endSalesDate,
                $page,
                $size
            );

            $sales = (array) data_get($payload, 'sales', []);
            $allSales = array_merge($allSales, $sales);
            $pageCount = max(1, (int) (data_get($payload, 'pageCount') ?: data_get($payload, 'totalPages') ?: 1));
            $page++;
        } while ($page <= $pageCount && ! empty($sales));

        return [
            'begin_sales_date' => $beginSalesDate,
            'end_sales_date' => $endSalesDate,
            'summary' => $this->summarizeSales($allSales),
            'sales' => array_map(fn (array $sale) => $this->formatSale($sale), $allSales),
        ];
    }

    private function requestSalesPage(
        string $token,
        string $merchantId,
        string $beginSalesDate,
        string $endSalesDate,
        int $page,
        int $size
    ): array {
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $financialPath = trim((string) config('services.ifood.financial_path', '/financial/v3.0'), '/');
        $url = "{$baseUrl}/{$financialPath}/merchants/{$merchantId}/sales";

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20));

        if ($this->ifood->isSandbox()) {
            $request = $request->withHeaders([
                'x-request-homologation' => 'true',
            ]);
        }

        $response = $request->get($url, [
            'beginSalesDate' => $beginSalesDate,
            'endSalesDate' => $endSalesDate,
            'page' => $page,
            'size' => $size,
        ]);

        if ($response->failed()) {
            $message = data_get($response->json(), 'message')
                ?: data_get($response->json(), 'error.message')
                ?: $response->body();

            throw new RuntimeException(
                'Erro ao consultar vendas iFood: ' . (is_string($message) ? $message : 'Falha na API Financial.')
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function summarizeSales(array $sales): array
    {
        $totalOrders = count($sales);
        $concluded = 0;
        $cancelled = 0;
        $grossTotal = 0.0;
        $netTotal = 0.0;

        foreach ($sales as $sale) {
            if (! is_array($sale)) {
                continue;
            }

            $status = strtoupper((string) data_get($sale, 'currentStatus', ''));

            if ($status === 'CONCLUDED') {
                $concluded++;
            }

            if (in_array($status, ['CANCELLED', 'CANCELED'], true)) {
                $cancelled++;
            }

            $grossTotal += (float) data_get($sale, 'saleGrossValue.bag', 0);
            $grossTotal += (float) data_get($sale, 'saleGrossValue.deliveryFee', 0);
            $grossTotal += (float) data_get($sale, 'saleGrossValue.serviceFee', 0);
            $netTotal += (float) data_get($sale, 'billingSummary.saleBalance', 0);
        }

        return [
            'total_orders' => $totalOrders,
            'concluded_orders' => $concluded,
            'cancelled_orders' => $cancelled,
            'gross_total' => round($grossTotal, 2),
            'net_total' => round($netTotal, 2),
        ];
    }

    private function formatSale(array $sale): array
    {
        $payments = collect((array) data_get($sale, 'payments.methods', []))
            ->map(static function (array $method): array {
                return [
                    'method' => data_get($method, 'method'),
                    'type' => data_get($method, 'type'),
                    'value' => (float) data_get($method, 'value', 0),
                    'brand' => data_get($method, 'card.brand'),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => data_get($sale, 'id'),
            'short_id' => data_get($sale, 'shortId'),
            'created_at' => data_get($sale, 'createdAt'),
            'status' => data_get($sale, 'currentStatus'),
            'channel' => data_get($sale, 'salesChannel'),
            'gross_value' => [
                'bag' => (float) data_get($sale, 'saleGrossValue.bag', 0),
                'delivery_fee' => (float) data_get($sale, 'saleGrossValue.deliveryFee', 0),
                'service_fee' => (float) data_get($sale, 'saleGrossValue.serviceFee', 0),
            ],
            'net_balance' => (float) data_get($sale, 'billingSummary.saleBalance', 0),
            'payments' => $payments,
        ];
    }
}
