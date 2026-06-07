<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'exists:stores,id'],
            'fulfillment_type' => ['required', Rule::in(['delivery', 'pickup'])],

            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string'],

            'address' => ['nullable', 'string', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:50'],
            'address_complement' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],

            'payment_method' => ['required', Rule::in(['cash', 'debit_card', 'credit_card', 'pix'])],
            'change_for' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::in(['sale', 'rent'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.observation' => ['nullable', 'string', 'max:255'],
            'items.*.options' => ['nullable', 'array'],
        ]);

        try {
            $store = Store::with('user')->findOrFail($validated['store_id']);

            if (!$store->is_open_now) {
                return response()->json([
                    'message' => 'A loja está fechada no momento.'
                ], 422);
            }

            if ($validated['fulfillment_type'] === 'delivery') {
                if (empty($validated['address']) || empty($validated['address_number'])) {
                    return response()->json([
                        'message' => 'Informe o endereço e o número para entrega.'
                    ], 422);
                }
            }

            DB::beginTransaction();

            $user = Auth::user() ?: $this->findOrCreateGuestUser(
                $validated['customer_name'],
                $validated['customer_phone']
            );

            [$itemsTotal, $itemsToCreate] = $this->prepareItems($validated['items'], $store->id);

            $deliveryFee = $validated['fulfillment_type'] === 'delivery'
                ? (float) ($store->delivery_fee ?? 0)
                : 0;

            $coupon = null;
            $discountAmount = 0;

            if (!empty($validated['coupon_code'])) {
                $coupon = $this->getValidCoupon($store->id, $validated['coupon_code'], $itemsTotal);
                $discountAmount = $this->calculateDiscount($coupon, $itemsTotal);
            }

            $totalAmount = max(0, $itemsTotal + $deliveryFee - $discountAmount);

            if (
                $validated['payment_method'] === 'cash' &&
                !empty($validated['change_for']) &&
                (float) $validated['change_for'] < $totalAmount
            ) {
                throw new \Exception('O valor para troco precisa ser maior que o total do pedido.');
            }

            $address = $validated['fulfillment_type'] === 'delivery'
                ? $this->formatAddress($validated)
                : 'Retirada no local';

            $order = Order::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'delivery_area_id' => null,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'delivery_fee' => $deliveryFee,
                'status' => 'pending',
                'type' => $validated['type'] ?? 'sale',
                'fulfillment_type' => $validated['fulfillment_type'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $this->onlyDigits($validated['customer_phone']),
                'address' => $address,
                'address_number' => $validated['address_number'] ?? null,
                'address_complement' => $validated['address_complement'] ?? null,
                'district' => $validated['district'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'payment_method' => $validated['payment_method'],
                'change_for' => $validated['change_for'] ?? null,
            ]);

            $order->items()->createMany($itemsToCreate);

            if ($coupon) {
                $coupon->increment('used_count');

                CouponUsage::create([
                    'coupon_id' => $coupon->id,
                    'store_id' => $store->id,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'discount_amount' => $discountAmount,
                ]);
            }

            $order->load(['items.product', 'user', 'deliveryArea']);

            $whatsappUrl = $this->buildWhatsAppUrl($store, $order);
            $order->update(['whatsapp_url' => $whatsappUrl]);

            DB::commit();

            $store->user?->notify(new NewOrderReceived($order));
            event(new NewOrderPlaced($order));

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'order' => $order->fresh(['items.product', 'user', 'deliveryArea']),
                'whatsapp_url' => $whatsappUrl,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Erro ao finalizar pedido.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function prepareItems(array $items, int $storeId): array
    {
        try {
            $itemsTotal = 0;
            $itemsToCreate = [];

            $productIds = collect($items)->pluck('product_id');
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($items as $itemData) {
                $product = $products->get($itemData['product_id']);

                if (!$product || (int) $product->store_id !== (int) $storeId || !$product->is_active) {
                    throw new \Exception("Produto indisponível: {$itemData['product_id']}.");
                }

                if ($product->manage_stock && $product->stock_quantity < $itemData['quantity']) {
                    throw new \Exception("Estoque insuficiente para {$product->name}.");
                }

                if ($product->manage_stock) {
                    $product->decrement('stock_quantity', $itemData['quantity']);
                }

                $options = collect($itemData['options'] ?? [])->map(fn($option) => [
                    'name' => $option['name'],
                    'group_name' => $option['group_name'],
                    'additional_price' => (float) $option['additional_price'],
                ]);

                $additionalPrice = $options->sum('additional_price');
                $unitPrice = (float) $product->price + $additionalPrice;
                $subtotal = $unitPrice * (int) $itemData['quantity'];

                $itemsTotal += $subtotal;

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'quantity' => (int) $itemData['quantity'],
                    'price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'observation' => $itemData['observation'] ?? null,
                    'options' => $options->isNotEmpty() ? $options->toJson() : null,
                ];
            }

            return [$itemsTotal, $itemsToCreate];
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar os itens do pedido: ' . $e->getMessage());
        }
    }

    private function getValidCoupon(int $storeId, string $code, float $subtotal): Coupon
    {
        try {
            $coupon = Coupon::where('store_id', $storeId)
                ->where('code', strtoupper(trim($code)))
                ->first();

            if (!$coupon) {
                throw new \Exception('Cupom não encontrado.');
            }

            if (!$coupon->is_active) {
                throw new \Exception('Este cupom está pausado.');
            }

            if ($coupon->expires_at && now()->greaterThan($coupon->expires_at)) {
                throw new \Exception('Este cupom expirou.');
            }

            if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
                throw new \Exception('Este cupom atingiu o limite de uso.');
            }

            if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
                throw new \Exception('O pedido mínimo para este cupom é R$ ' . number_format((float) $coupon->min_order_amount, 2, ',', '.'));
            }

            return $coupon;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao validar cupom: ' . $e->getMessage());
        }
    }

    private function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        try {
            if ($coupon->type === 'percentage') {
                $discount = $subtotal * ((float) $coupon->value / 100);

                if ($coupon->max_discount_amount !== null) {
                    $discount = min($discount, (float) $coupon->max_discount_amount);
                }

                return round(min($discount, $subtotal), 2);
            }

            return round(min((float) $coupon->value, $subtotal), 2);
        } catch (\Exception $e) {
            throw new \Exception('Erro ao calcular desconto: ' . $e->getMessage());
        }
    }

    private function findOrCreateGuestUser(string $name, string $phone): User
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        $email = "cliente_{$digits}@checkout.local";

        $user = User::where('role', 'customer')
            ->where(function ($q) use ($digits, $email) {
                $q->where('phone', $digits)
                    ->orWhere('email', $email);
            })
            ->first();

        if ($user) {
            $user->update([
                'phone' => $digits,
                'name' => $user->name ?: $name,
                'email' => $user->email ?: $email,
            ]);

            return $user;
        }

        return User::create([
            'name' => $name,
            'phone' => $digits,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'role' => 'customer',
        ]);
    }

    private function formatAddress(array $data): string
    {
        return collect([
            $data['address'] ?? null,
            $data['address_number'] ?? null,
            $data['address_complement'] ?? null,
        ])->filter()->implode(', ');
    }

    private function buildWhatsAppUrl(Store $store, Order $order): string
    {
        $phone = $this->onlyDigits(
            $store->whatsapp_number
        );

        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }

        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($this->buildWhatsAppMessage($order));
    }

    private function buildWhatsAppMessage(Order $order): string
    {
        $lines = [];
        $lines[] = "*Novo pedido #{$order->id}*";
        $lines[] = "";
        $lines[] = "*Cliente:* {$order->customer_name}";
        $lines[] = "*WhatsApp:* {$order->customer_phone}";
        $lines[] = "*Tipo:* " . ($order->fulfillment_type === 'pickup' ? 'Retirada no local' : 'Entrega');

        if ($order->fulfillment_type === 'delivery') {
            $lines[] = "*Endereço:* {$order->address}";
            if ($order->district) {
                $lines[] = "*Bairro:* {$order->district}";
            }
        }

        $lines[] = "";
        $lines[] = "*Itens:*";

        foreach ($order->items as $item) {
            $lines[] = "{$item->quantity}x {$item->product->name} - R$ " . number_format((float) $item->subtotal, 2, ',', '.');

            $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;

            foreach (($options ?? []) as $option) {
                $price = number_format((float) ($option['additional_price'] ?? 0), 2, ',', '.');
                $lines[] = "  + {$option['name']} ({$option['group_name']}) R$ {$price}";
            }

            if ($item->observation) {
                $lines[] = "  Obs: {$item->observation}";
            }
        }

        $subtotal = (float) $order->total_amount - (float) $order->delivery_fee + (float) $order->discount_amount;

        $lines[] = "";
        $lines[] = "*Subtotal:* R$ " . number_format($subtotal, 2, ',', '.');
        $lines[] = "*Entrega:* R$ " . number_format((float) $order->delivery_fee, 2, ',', '.');

        if ($order->discount_amount > 0) {
            $lines[] = "*Cupom:* {$order->coupon_code}";
            $lines[] = "*Desconto:* - R$ " . number_format((float) $order->discount_amount, 2, ',', '.');
        }

        $lines[] = "*Total:* R$ " . number_format((float) $order->total_amount, 2, ',', '.');
        $lines[] = "*Pagamento:* " . $this->paymentLabel($order->payment_method);

        if ($order->payment_method === 'cash' && $order->change_for) {
            $lines[] = "*Troco para:* R$ " . number_format((float) $order->change_for, 2, ',', '.');
        }

        return implode("\n", $lines);
    }

    private function paymentLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Dinheiro',
            'debit_card' => 'Cartão de débito',
            'credit_card' => 'Cartão de crédito',
            'pix' => 'Pix',
            default => 'Não informado',
        };
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value);
    }
}
