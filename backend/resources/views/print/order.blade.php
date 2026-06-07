<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Pedido #{{ $order->id }}</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            width: 80mm;
            margin: 0;
            padding: 5mm;
            background-color: #fff;
            color: #000;
        }
        .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; }
        .details { margin: 10px 0; font-size: 14px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; border-bottom: 1px solid #000; }
        .total { border-top: 1px dashed #000; margin-top: 10px; padding-top: 5px; font-weight: bold; }

        @media print {
            .no-print { display: none !important; }
            @page { margin: 0; }
            body { padding: 0; }
        }
    </style>
</head>
<body> <div class="no-print" style="margin-bottom: 15px; text-align: center;">
        <button onclick="window.print()" style="padding: 8px 16px; cursor: pointer; font-weight: bold;">
            Imprimir Manualmente
        </button>
        <hr>
    </div>

    <div class="header">
        <h2>{{ $order->store->name ?? 'Minha Loja' }}</h2>
        <p>Pedido: #{{ $order->id }}</p>
        <p>{{ $order->created_at->format('d/m/Y H:i') }}</p>
    </div>

    <div class="details">
        <strong>Cliente:</strong> {{ $order->user->name }} <br>
        <strong>Entrega:</strong> {{ $order->address }} <br>
        <strong>Bairro:</strong> {{ $order->district ?? 'N/A' }}
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
                            @php
                                // Garante o decode caso venha como string do banco
                                $optionsArray = is_string($item->options) ? json_decode($item->options, true) : $item->options;
                            @endphp
                            @if(is_array($optionsArray))
                                <br><small>+ {{ implode(', ', array_column($optionsArray, 'name')) }}</small>
                            @endif
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
        @php
            $subtotal = $order->total_amount + $order->discount_amount - $order->delivery_fee;
        @endphp
        Subtotal: R$ {{ number_format($subtotal, 2, ',', '.') }} <br>

        @if($order->discount_amount > 0)
            Desconto: - R$ {{ number_format($order->discount_amount, 2, ',', '.') }} <br>
        @endif

        Entrega: R$ {{ number_format($order->delivery_fee, 2, ',', '.') }} <br>
        <strong>TOTAL: R$ {{ number_format($order->total_amount, 2, ',', '.') }}</strong>
    </div>

    <div style="text-align: center; margin-top: 20px;">
        <p>Obrigado pela preferência!</p>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            setTimeout(function() {
                window.print();
            }, 300);
        });
    </script>
</body>
</html>
