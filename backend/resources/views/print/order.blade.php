<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Pedido #{{ $order->id }}</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace; /* Fonte estilo cupom */
            width: 80mm; /* Largura padrão térmica */
            margin: 0;
            padding: 5mm;
        }
        .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; }
        .details { margin: 10px 0; font-size: 14px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; border-bottom: 1px solid #000; }
        .total { border-top: 1px dashed #000; margin-top: 10px; padding-top: 5px; font-weight: bold; }

        /* O comando que faz a mágica */
        @media print {
            .no-print { display: none; }
            @page { margin: 0; }
        }
    </style>
</head>
<body onload="window.print()"> <div class="no-print">
        <button onclick="window.print()">Imprimir Manualmente</button>
    </div>

    <div class="header">
        <h2>{{ $order->store->name }}</h2>
        <p>Pedido: #{{ $order->id }}</p>
        <p>{{ $order->created_at->format('d/m/Y H:i') }}</p>
    </div>

    <div class="details">
        <strong>Cliente:</strong> {{ $order->user->name }} <br>
        <strong>Entrega:</strong> {{ $order->address }} <br>
        <strong>Bairro:</strong> {{ $order->deliveryArea->district_name }}
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Qtd</th>
                <th>Item</th>
                <th>V.Un</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->quantity }}x</td>
                    <td>
                        {{ $item->product->name }}
                        @if($item->options)
                            <br><small>+ {{ implode(', ', array_column(json_decode($item->options, true), 'name')) }}</small>
                        @endif
                        @if($item->observation)
                            <br><small><strong>Obs:</strong> {{ $item->observation }}</small>
                        @endif
                    </td>
                    <td>R$ {{ number_format($item->price, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        Subtotal: R$ {{ number_format($order->total_amount - $order->delivery_fee, 2, ',', '.') }} <br>
        Entrega: R$ {{ number_format($order->delivery_fee, 2, ',', '.') }} <br>
        <strong>TOTAL: R$ {{ number_format($order->total_amount, 2, ',', '.') }}</strong>
    </div>

    <div style="text-align: center; margin-top: 20px;">
        <p>Obrigado pela preferência!</p>
    </div>
</body>
</html>
