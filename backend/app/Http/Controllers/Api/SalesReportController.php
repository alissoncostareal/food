<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SalesReportController extends Controller
{
    use ResolvesMerchantStore;

    public function exportMonthly(Request $request)
    {
        try {
            $store = $this->merchantStore()->load('plan');

            $validated = $request->validate([
                'month' => ['nullable', 'date_format:Y-m'],
                'format' => ['nullable', 'in:xls,csv'],
            ]);

            $referenceMonth = CarbonImmutable::createFromFormat(
                'Y-m',
                $validated['month'] ?? now(config('app.timezone', 'America/Fortaleza'))->format('Y-m'),
                config('app.timezone', 'America/Fortaleza')
            );

            $startDate = $referenceMonth->startOfMonth();
            $endDate = $referenceMonth->endOfMonth();
            $ignoredStatus = ['canceled', 'cancelled'];

            $orders = Order::query()
                ->where('store_id', $store->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', $ignoredStatus)
                ->with(['items.product:id,name', 'coupon:id,code,description'])
                ->orderBy('created_at')
                ->get();

            $summary = [
                'store_name' => $store->name,
                'period' => $referenceMonth->format('m/Y'),
                'orders_count' => $orders->count(),
                'gross_total' => (float) $orders->sum('total_amount'),
                'delivery_total' => (float) $orders->sum('delivery_fee'),
                'discount_total' => (float) $orders->sum('discount_amount'),
                'average_ticket' => (float) round($orders->count() > 0 ? $orders->sum('total_amount') / $orders->count() : 0, 2),
            ];

            $paymentTotals = $orders
                ->groupBy(fn ($order) => $order->payment_method ?: 'nao_informado')
                ->map(fn ($group, $paymentMethod) => [
                    'payment_method' => $this->paymentMethodLabel($paymentMethod),
                    'orders_count' => $group->count(),
                    'total' => (float) $group->sum('total_amount'),
                ])
                ->values();

            $statusTotals = $orders
                ->groupBy(fn ($order) => $order->status ?: 'desconhecido')
                ->map(fn ($group, $status) => [
                    'status' => $this->statusLabel($status),
                    'orders_count' => $group->count(),
                    'total' => (float) $group->sum('total_amount'),
                ])
                ->values();

            $productTotals = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.store_id', $store->id)
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->whereNotIn('orders.status', $ignoredStatus)
                ->select(
                    DB::raw("COALESCE(products.name, 'Produto removido') as product_name"),
                    DB::raw('SUM(order_items.quantity) as quantity'),
                    DB::raw('SUM(order_items.subtotal) as total')
                )
                ->groupBy(DB::raw("COALESCE(products.name, 'Produto removido')"))
                ->orderByDesc('quantity')
                ->get();

            $format = $validated['format'] ?? 'xls';
            $content = $format === 'csv'
                ? $this->buildCsv($summary, $paymentTotals, $statusTotals, $productTotals, $orders)
                : $this->buildExcelHtml($summary, $paymentTotals, $statusTotals, $productTotals, $orders);

            $filename = 'relatorio-vendas-' . $store->slug . '-' . $referenceMonth->format('Y-m') . '.' . $format;
            $contentType = $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.ms-excel; charset=UTF-8';

            return response($content, Response::HTTP_OK, [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Ocorreu um erro ao exportar o relatório de vendas.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildCsv($summary, $paymentTotals, $statusTotals, $productTotals, $orders): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");

        $this->putSection($handle, 'Resumo do mês');
        $this->putRow($handle, ['Loja', $summary['store_name']]);
        $this->putRow($handle, ['Período', $summary['period']]);
        $this->putRow($handle, ['Pedidos válidos', $summary['orders_count']]);
        $this->putRow($handle, ['Faturamento bruto', $this->money($summary['gross_total'])]);
        $this->putRow($handle, ['Taxas de entrega', $this->money($summary['delivery_total'])]);
        $this->putRow($handle, ['Descontos', $this->money($summary['discount_total'])]);
        $this->putRow($handle, ['Ticket médio', $this->money($summary['average_ticket'])]);
        $this->putBlankRow($handle);

        $this->putSection($handle, 'Resumo por pagamento');
        $this->putRow($handle, ['Forma de pagamento', 'Pedidos', 'Total']);
        foreach ($paymentTotals as $payment) {
            $this->putRow($handle, [
                $payment['payment_method'],
                $payment['orders_count'],
                $this->money($payment['total']),
            ]);
        }
        $this->putBlankRow($handle);

        $this->putSection($handle, 'Resumo por status');
        $this->putRow($handle, ['Status', 'Pedidos', 'Total']);
        foreach ($statusTotals as $status) {
            $this->putRow($handle, [
                $status['status'],
                $status['orders_count'],
                $this->money($status['total']),
            ]);
        }
        $this->putBlankRow($handle);

        $this->putSection($handle, 'Produtos vendidos');
        $this->putRow($handle, ['Produto', 'Quantidade', 'Total vendido']);
        foreach ($productTotals as $product) {
            $this->putRow($handle, [
                $product->product_name,
                (int) $product->quantity,
                $this->money($product->total),
            ]);
        }
        $this->putBlankRow($handle);

        $this->putSection($handle, 'Pedidos detalhados');
        $this->putRow($handle, [
            'Pedido',
            'Data',
            'Cliente',
            'Telefone',
            'Tipo',
            'Pagamento',
            'Status',
            'Subtotal dos itens',
            'Entrega',
            'Desconto',
            'Total',
            'Cupom',
            'Itens',
            'Observação',
        ]);

        foreach ($orders as $order) {
            $itemsDescription = $order->items
                ->map(fn ($item) => ($item->product->name ?? 'Produto removido') . ' x' . $item->quantity)
                ->implode(' | ');

            $this->putRow($handle, [
                '#' . $order->id,
                optional($order->created_at)->format('d/m/Y H:i'),
                $order->customer_name ?? 'Cliente',
                $order->customer_phone ?? '',
                $this->orderTypeLabel($order->fulfillment_type ?: $order->type),
                $this->paymentMethodLabel($order->payment_method),
                $this->statusLabel($order->status),
                $this->money($order->items->sum('subtotal')),
                $this->money($order->delivery_fee),
                $this->money($order->discount_amount),
                $this->money($order->total_amount),
                $order->coupon_display_code ?? '',
                $itemsDescription,
                $order->observation ?? '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function buildExcelHtml($summary, $paymentTotals, $statusTotals, $productTotals, $orders): string
    {
        $sections = [
            [
                'title' => 'Resumo do mês',
                'headers' => ['Indicador', 'Valor'],
                'rows' => [
                    ['Loja', $summary['store_name']],
                    ['Período', $summary['period']],
                    ['Pedidos válidos', $summary['orders_count']],
                    ['Faturamento bruto', $this->money($summary['gross_total'])],
                    ['Taxas de entrega', $this->money($summary['delivery_total'])],
                    ['Descontos', $this->money($summary['discount_total'])],
                    ['Ticket médio', $this->money($summary['average_ticket'])],
                ],
            ],
            [
                'title' => 'Resumo por pagamento',
                'headers' => ['Forma de pagamento', 'Pedidos', 'Total'],
                'rows' => $paymentTotals
                    ->map(fn ($payment) => [
                        $payment['payment_method'],
                        $payment['orders_count'],
                        $this->money($payment['total']),
                    ])
                    ->all(),
            ],
            [
                'title' => 'Resumo por status',
                'headers' => ['Status', 'Pedidos', 'Total'],
                'rows' => $statusTotals
                    ->map(fn ($status) => [
                        $status['status'],
                        $status['orders_count'],
                        $this->money($status['total']),
                    ])
                    ->all(),
            ],
            [
                'title' => 'Produtos vendidos',
                'headers' => ['Produto', 'Quantidade', 'Total vendido'],
                'rows' => $productTotals
                    ->map(fn ($product) => [
                        $product->product_name,
                        (int) $product->quantity,
                        $this->money($product->total),
                    ])
                    ->all(),
            ],
            [
                'title' => 'Pedidos detalhados',
                'headers' => [
                    'Pedido',
                    'Data',
                    'Cliente',
                    'Telefone',
                    'Tipo',
                    'Pagamento',
                    'Status',
                    'Subtotal dos itens',
                    'Entrega',
                    'Desconto',
                    'Total',
                    'Cupom',
                    'Itens',
                    'Observação',
                ],
                'rows' => $orders
                    ->map(function ($order) {
                        $itemsDescription = $order->items
                            ->map(fn ($item) => ($item->product->name ?? 'Produto removido') . ' x' . $item->quantity)
                            ->implode(' | ');

                        return [
                            '#' . $order->id,
                            optional($order->created_at)->format('d/m/Y H:i'),
                            $order->customer_name ?? 'Cliente',
                            $order->customer_phone ?? '',
                            $this->orderTypeLabel($order->fulfillment_type ?: $order->type),
                            $this->paymentMethodLabel($order->payment_method),
                            $this->statusLabel($order->status),
                            $this->money($order->items->sum('subtotal')),
                            $this->money($order->delivery_fee),
                            $this->money($order->discount_amount),
                            $this->money($order->total_amount),
                            $order->coupon_display_code ?? '',
                            $itemsDescription,
                            $order->observation ?? '',
                        ];
                    })
                    ->all(),
            ],
        ];

        $html = '<html><head><meta charset="UTF-8"><style>';
        $html .= 'body{font-family:Arial,sans-serif;} table{border-collapse:collapse;margin-bottom:24px;width:100%;}';
        $html .= 'th{background:#111827;color:#fff;font-weight:bold;} th,td{border:1px solid #d1d5db;padding:8px;font-size:12px;}';
        $html .= 'h2{margin-top:24px;color:#991b1b;} .money{mso-number-format:"\\#\\.\\#\\#0\\,00";}';
        $html .= '</style></head><body>';
        $html .= '<h1>' . $this->escape('Relatório de vendas - ' . $summary['period']) . '</h1>';

        foreach ($sections as $section) {
            $html .= '<h2>' . $this->escape($section['title']) . '</h2>';
            $html .= '<table><thead><tr>';

            foreach ($section['headers'] as $header) {
                $html .= '<th>' . $this->escape($header) . '</th>';
            }

            $html .= '</tr></thead><tbody>';

            if (count($section['rows']) === 0) {
                $html .= '<tr><td colspan="' . count($section['headers']) . '">Sem registros no período.</td></tr>';
            }

            foreach ($section['rows'] as $row) {
                $html .= '<tr>';

                foreach ($row as $value) {
                    $html .= '<td>' . $this->escape((string) $value) . '</td>';
                }

                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        return $html . '</body></html>';
    }

    private function putSection($handle, string $title): void
    {
        $this->putRow($handle, [$title]);
    }

    private function putBlankRow($handle): void
    {
        $this->putRow($handle, []);
    }

    private function putRow($handle, array $row): void
    {
        fputcsv($handle, $row, ';');
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function paymentMethodLabel(?string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => 'Dinheiro',
            'credit_card' => 'Cartão de crédito',
            'debit_card' => 'Cartão de débito',
            'pix' => 'Pix',
            default => 'Não informado',
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pedido recebido',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto para entrega',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Pedido entregue',
            'canceled', 'cancelled' => 'Pedido cancelado',
            default => 'Status desconhecido',
        };
    }

    private function orderTypeLabel(?string $type): string
    {
        return match ($type) {
            'pickup' => 'Retirada',
            'delivery' => 'Entrega',
            'local' => 'Balcão/local',
            default => 'Não informado',
        };
    }
}
