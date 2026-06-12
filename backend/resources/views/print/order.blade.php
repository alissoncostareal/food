<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Pedido #{{ $order->display_code }}</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            width: 80mm;
            margin: 0;
            padding: 5mm;
            background-color: #fff;
            color: #000;
            font-size: 12px;
        }

        .no-print {
            margin-bottom: 15px;
            text-align: center;
        }

        .no-print button {
            padding: 8px 16px;
            cursor: pointer;
            font-weight: bold;
        }

        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .header h2 {
            margin: 0 0 4px;
            font-size: 18px;
            text-transform: uppercase;
        }

        .header p {
            margin: 2px 0;
        }

        .section {
            border-bottom: 1px dashed #000;
            padding: 8px 0;
        }

        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .line {
            margin: 2px 0;
            word-break: break-word;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        .table th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            font-size: 11px;
        }

        .table td {
            vertical-align: top;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }

        .table td:last-child,
        .table th:last-child {
            text-align: right;
            white-space: nowrap;
        }

        .item-name {
            font-weight: bold;
        }

        .small {
            font-size: 10px;
            line-height: 1.35;
        }

        .option {
            margin-left: 4px;
        }

        .totals {
            padding-top: 8px;
            font-size: 13px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin: 3px 0;
        }

        .grand-total {
            border-top: 1px dashed #000;
            margin-top: 6px;
            padding-top: 6px;
            font-weight: bold;
            font-size: 16px;
        }

        .center {
            text-align: center;
        }

        .footer {
            text-align: center;
            margin-top: 14px;
            font-size: 11px;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            @page {
                margin: 0;
            }

            body {
                padding: 0;
            }
        }
    </style>
</head>

<body>
    @php
        $statusLabels = [
            'pending' => 'Pedido recebido',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto para entrega',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Pedido entregue',
            'canceled' => 'Pedido cancelado',
        ];

        $paymentLabels = [
            'pix' => 'Pix',
            'money' => 'Dinheiro',
            'cash' => 'Dinheiro',
            'credit_card' => 'Cartão de crédito',
            'debit_card' => 'Cartão de débito',
            'card' => 'Cartão',
        ];

        $fulfillmentLabels = [
            'delivery' => 'Entrega',
            'pickup' => 'Retirada',
        ];

        $statusLabel = $order->status_label ?? ($statusLabels[$order->status] ?? 'Status desconhecido');
        $paymentLabel = $paymentLabels[$order->payment_method] ?? ($order->payment_method ? ucfirst($order->payment_method) : 'Não informado');
        $fulfillmentLabel = $fulfillmentLabels[$order->fulfillment_type] ?? ($order->type === 'sale' ? 'Entrega' : 'Pedido');

        $ifoodOrderTypeLabels = [
            'DELIVERY' => 'Delivery iFood',
            'TAKEOUT' => 'Retirada iFood',
            'INDOOR' => 'Consumo no local iFood',
        ];

        $ifoodDeliveredByLabels = [
            'IFOOD' => 'Entrega iFood',
            'MERCHANT' => 'Entrega própria',
        ];

        $ifoodOrderTypeLabel = $ifoodOrderTypeLabels[strtoupper((string) ($order->ifood_order_type ?? ''))] ?? null;
        $ifoodDeliveredByLabel = $ifoodDeliveredByLabels[strtoupper((string) ($order->ifood_delivered_by ?? ''))] ?? null;

        $couponCode = $order->coupon?->code
            ?? $order->coupon_display_code
            ?? $order->coupon_code
            ?? null;

        $couponDescription = $order->coupon?->description
            ?? $order->coupon_display_description
            ?? $order->coupon_description
            ?? null;

        $discountAmount = (float) ($order->discount_amount ?? 0);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $totalAmount = (float) ($order->total_amount ?? 0);
        $itemsSubtotal = ($totalAmount - $deliveryFee) + $discountAmount;

        $fullAddressParts = array_filter([
            $order->address,
            $order->address_number ? 'Nº ' . $order->address_number : null,
            $order->address_complement,
        ]);

        $fullAddress = implode(', ', $fullAddressParts);
    @endphp

    <div class="no-print">
        <button onclick="window.print()">Imprimir Manualmente</button>
        <hr>
    </div>

    <div class="header">
        <h2>{{ $order->store->name ?? 'Minha Loja' }}</h2>
        <p><strong>Pedido #{{ $order->display_code }}</strong></p>
        @if($order->order_source === 'ifood')
            <p><strong>Pedido iFood</strong></p>
            @if($ifoodOrderTypeLabel)
                <p>{{ $ifoodOrderTypeLabel }}</p>
            @endif
            @if($ifoodDeliveredByLabel)
                <p>{{ $ifoodDeliveredByLabel }}</p>
            @endif
        @endif
        @if($order->ifood_delivery_localizer && $order->ifood_delivered_by === 'MERCHANT')
            <p><strong>Cód. entrega iFood:</strong> {{ $order->ifood_delivery_localizer }}</p>
        @endif
        <p>{{ $order->created_at ? $order->created_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}</p>
        <p>{{ $statusLabel }}</p>
    </div>

    <div class="section">
        <div class="section-title">Cliente</div>

        <div class="line">
            <strong>Nome:</strong>
            {{ $order->customer_name ?: ($order->user?->name ?? 'Cliente') }}
        </div>

        <div class="line">
            <strong>Telefone:</strong>
            {{ $order->customer_phone ?: ($order->user?->phone ?? 'N/A') }}
        </div>

        <div class="line">
            <strong>Tipo:</strong>
            {{ $fulfillmentLabel }}
        </div>

        <div class="line">
            <strong>Pagamento:</strong>
            {{ $paymentLabel }}
        </div>

        @if($order->payment_method === 'money' || $order->payment_method === 'cash')
            <div class="line">
                <strong>Troco para:</strong>
                {{ $order->change_for ? 'R$ ' . number_format((float) $order->change_for, 2, ',', '.') : 'Não informado' }}
            </div>
        @endif
    </div>

    @if(($order->fulfillment_type ?? 'delivery') === 'delivery')
        <div class="section">
            <div class="section-title">Entrega</div>

            <div class="line">
                <strong>Endereço:</strong>
                {{ $fullAddress ?: 'N/A' }}
            </div>

            <div class="line">
                <strong>Bairro:</strong>
                {{ $order->deliveryArea?->district_name ?? $order->district ?? 'N/A' }}
            </div>
        </div>
    @endif

    @if($order->observation)
        <div class="section">
            <div class="section-title">Observação do pedido</div>
            <div class="line">{{ $order->observation }}</div>
        </div>
    @endif

    <div class="section">
        <div class="section-title">Itens</div>

        <table class="table">
            <thead>
                <tr>
                    <th style="width: 12%;">Qtd</th>
                    <th>Item</th>
                    <th style="width: 24%;">Total</th>
                </tr>
            </thead>

            <tbody>
                @foreach($order->items as $item)
                    @php
                        $optionsArray = is_array($item->options)
                            ? $item->options
                            : (is_string($item->options) ? (json_decode($item->options, true) ?: []) : []);

                        $itemName = trim((string) ($item->product?->name ?? ''));
                        if ($itemName === '') {
                            $itemName = trim((string) ($item->observation ?? ''));
                        }
                        if ($itemName === '') {
                            $itemName = 'Item';
                        }
                    @endphp

                    <tr>
                        <td>{{ $item->quantity }}x</td>

                        <td>
                            <div class="item-name">
                                {{ $itemName }}
                            </div>

                            <div class="small">
                                Unit.: R$ {{ number_format((float) $item->price, 2, ',', '.') }}
                            </div>

                            @if(count($optionsArray))
                                <div class="small">
                                    @foreach($optionsArray as $option)
                                        <div class="option">
                                            + {{ $option['name'] ?? 'Opcional' }}
                                            @if(!empty($option['group_name']))
                                                ({{ $option['group_name'] }})
                                            @endif

                                            @if((float) ($option['additional_price'] ?? 0) > 0)
                                                + R$ {{ number_format((float) $option['additional_price'], 2, ',', '.') }}
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($item->observation && $item->observation !== $itemName)
                                <div class="small">
                                    <strong>Obs:</strong> {{ $item->observation }}
                                </div>
                            @endif
                        </td>

                        <td>
                            R$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="totals">
        <div class="total-row">
            <span>Subtotal</span>
            <span>R$ {{ number_format($itemsSubtotal, 2, ',', '.') }}</span>
        </div>

        @if($discountAmount > 0)
            <div class="total-row">
                <span>
                    Desconto
                    @if($couponCode)
                        ({{ $couponCode }})
                    @else
                        (Cupom removido)
                    @endif
                </span>
                <span>- R$ {{ number_format($discountAmount, 2, ',', '.') }}</span>
            </div>

            @if($couponDescription)
                <div class="small">
                    Cupom: {{ $couponDescription }}
                </div>
            @endif
        @endif

        <div class="total-row">
            <span>Entrega</span>
            <span>R$ {{ number_format($deliveryFee, 2, ',', '.') }}</span>
        </div>

        <div class="total-row grand-total">
            <span>TOTAL</span>
            <span>R$ {{ number_format($totalAmount, 2, ',', '.') }}</span>
        </div>
    </div>

    <div class="footer">
        <p>Obrigado pela preferência!</p>
        <p>Conferir itens antes de sair para entrega.</p>
    </div>
</body>
</html>
