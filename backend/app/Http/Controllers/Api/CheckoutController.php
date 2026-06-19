<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderPixPaymentService;
use App\Services\OrderStockService;
use App\Services\WhatsappOrderUrlService;
use App\Support\BrazilPhone;
use App\Support\DeliveryAreaMatcher;
use App\Support\StreetAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(Request $request, OrderPixPaymentService $pixPayments, OrderStockService $stock, WhatsappOrderUrlService $whatsappUrls)
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
            'city' => ['nullable', 'string', 'max:120'],
            'delivery_area_id' => ['nullable', 'integer', 'exists:delivery_areas,id'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],

            'payment_method' => ['required', Rule::in(Store::PAYMENT_METHODS)],
            'card_token' => ['nullable', 'string', 'max:255'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
            'change_for' => ['nullable', 'numeric', 'min:0'],

            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],
            'coupon_code' => ['nullable', 'string', 'max:50'],

            'type' => ['nullable', Rule::in(['sale', 'rent'])],
            'observation' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.observation' => ['nullable', 'string', 'max:255'],
            'items.*.options' => ['nullable', 'array'],
        ]);

        $validated = $this->normalizeDeliveryAddress($validated);

        try {
            $store = Store::with('user')->findOrFail($validated['store_id']);

            if (!$store->is_open_now) {
                return response()->json([
                    'message' => 'A loja está fechada no momento.'
                ], 422);
            }

            if (! $store->acceptsPaymentMethod($validated['payment_method'])) {
                return response()->json([
                    'message' => 'Esta loja não aceita a forma de pagamento selecionada.',
                ], 422);
            }

            $isOnlinePix = $validated['payment_method'] === Store::PAYMENT_PIX_ONLINE;
            $isOnlineCard = $validated['payment_method'] === Store::PAYMENT_CREDIT_CARD_ONLINE;
            $isOnlinePayment = $isOnlinePix || $isOnlineCard;

            if ($isOnlinePix && ! $pixPayments->storeAcceptsOnlinePayments($store, Store::PAYMENT_PIX_ONLINE)) {
                return response()->json([
                    'message' => 'Pix online não está disponível nesta loja.',
                ], 422);
            }

            if ($isOnlineCard && ! $pixPayments->storeAcceptsCardOnline($store)) {
                return response()->json([
                    'message' => 'Cartão online não está disponível nesta loja. Use Pagar.me em Recebimentos.',
                ], 422);
            }

            if ($isOnlineCard && blank($validated['card_token'] ?? null)) {
                return response()->json([
                    'message' => 'Informe os dados do cartão para continuar.',
                ], 422);
            }

            $phoneDigits = $this->onlyDigits($validated['customer_phone']);

            if (strlen($phoneDigits) < 10) {
                return response()->json([
                    'message' => 'Informe um WhatsApp válido.',
                ], 422);
            }

            if ($validated['fulfillment_type'] === 'delivery' && empty($validated['address'])) {
                return response()->json([
                    'message' => 'Informe o endereço de entrega.',
                ], 422);
            }

            $deliveryArea = null;
            $deliveryFee = 0;

            if ($validated['fulfillment_type'] === 'delivery') {
                $deliveryArea = $this->resolveDeliveryArea($store, $validated);
                $validated = $this->resolveDeliveryDistrict($validated, $deliveryArea);

                if (blank($validated['district'] ?? null)) {
                    return response()->json([
                        'message' => 'Escolha o endereço na lista de sugestões.',
                    ], 422);
                }

                $deliveryFee = $deliveryArea
                    ? (float) $deliveryArea->fee
                    : (float) ($store->delivery_fee ?? 0);
            }

            DB::beginTransaction();

            $user = Auth::user() ?: $this->findOrCreateGuestUser(
                $validated['customer_name'],
                $validated['customer_phone']
            );

            [$itemsTotal, $itemsToCreate] = $this->prepareItems($validated['items'], $store->id);

            $coupon = null;
            $discountAmount = 0;

            if (!empty($validated['coupon_id']) || !empty($validated['coupon_code'])) {
                $coupon = $this->getValidCoupon(
                    $store->id,
                    $itemsTotal,
                    $validated['coupon_id'] ?? null,
                    $validated['coupon_code'] ?? null
                );

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
                'delivery_area_id' => $deliveryArea?->id,

                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'coupon_description' => $coupon?->description,

                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'delivery_fee' => $deliveryFee,

                'status' => 'pending',
                'type' => $validated['type'] ?? 'sale',
                'fulfillment_type' => $validated['fulfillment_type'],

                'customer_name' => $validated['customer_name'],
                'customer_phone' => $this->normalizeBrazilPhone($validated['customer_phone']),

                'address' => $address,
                'address_number' => $validated['address_number'] ?? null,
                'address_complement' => $validated['address_complement'] ?? null,
                'district' => $validated['district'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,

                'payment_method' => $validated['payment_method'],
                'payment_status' => $isOnlinePayment
                    ? OrderPixPaymentService::STATUS_AWAITING
                    : OrderPixPaymentService::STATUS_NOT_REQUIRED,
                'payment_channel' => $isOnlinePayment ? 'online' : 'offline',
                'change_for' => $validated['change_for'] ?? null,
                'observation' => $validated['observation'] ?? null,
            ]);

            $order->items()->createMany($itemsToCreate);

            $user = $this->syncCustomerProfile($user, $validated);
            $order->setRelation('user', $user);

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

            $order->load(['items.product', 'user', 'deliveryArea', 'coupon']);

            $whatsappUrl = null;

            if (! $isOnlinePayment) {
                $whatsappUrl = $whatsappUrls->buildForOrder($order, $store);

                if ($whatsappUrl) {
                    $order->update(['whatsapp_url' => $whatsappUrl]);
                    $order->refresh();
                }
            }

            DB::commit();

            $freshOrder = $order->fresh(['items.product', 'user', 'deliveryArea', 'coupon', 'store']);

            $paymentPayload = null;

            if ($isOnlinePix) {
                try {
                    $paymentPayload = $pixPayments->createPixCharge($freshOrder);
                    $freshOrder->refresh();
                } catch (\Throwable $e) {
                    Log::warning('Pix charge failed', [
                        'order_id' => $freshOrder->id,
                        'store_id' => $freshOrder->store_id,
                        'provider' => $freshOrder->store?->paymentPixProvider?->provider,
                        'error' => $e->getMessage(),
                    ]);

                    $freshOrder->forceFill([
                        'status' => 'canceled',
                        'payment_status' => OrderPixPaymentService::STATUS_FAILED,
                    ])->save();

                    $stock->restoreIfNeeded($freshOrder->fresh());

                    $details = $this->publicPixFailureDetails($e->getMessage());

                    return response()->json([
                        'message' => 'Não foi possível gerar o Pix. Tente outra forma de pagamento.',
                        'details' => $details ?? (config('app.debug') ? $e->getMessage() : null),
                    ], 422);
                }
            } elseif ($isOnlineCard) {
                try {
                    $paymentPayload = $pixPayments->createCardCharge(
                        $freshOrder,
                        (string) $validated['card_token'],
                        (int) ($validated['installments'] ?? 1)
                    );
                    $freshOrder->refresh();
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Pagamento recusado. Tente outro cartão ou forma de pagamento.',
                        'details' => config('app.debug') ? $e->getMessage() : null,
                    ], 422);
                }
            } else {
                $orderId = $freshOrder->id;

                dispatch(function () use ($orderId) {
                    $order = Order::query()
                        ->with(['items.product', 'user', 'deliveryArea', 'coupon', 'store'])
                        ->find($orderId);

                    if ($order) {
                        event(new NewOrderPlaced($order));
                    }
                })->afterResponse();
            }

            $responseMessage = match (true) {
                $isOnlinePix => 'Pedido reservado. Conclua o pagamento Pix para confirmar.',
                $isOnlineCard && ($paymentPayload['status'] ?? null) === OrderPixPaymentService::STATUS_PAID
                    => 'Pagamento aprovado! Pedido enviado para a loja.',
                $isOnlineCard => 'Pagamento em análise. Aguarde a confirmação.',
                default => 'Pedido criado com sucesso.',
            };

            if (
                $isOnlineCard
                && ($paymentPayload['status'] ?? null) === OrderPixPaymentService::STATUS_PAID
                && ($whatsappUrl = $whatsappUrls->buildForOrder($freshOrder, $store))
            ) {
                $freshOrder->update(['whatsapp_url' => $whatsappUrl]);
            }

            return response()->json([
                'message' => $responseMessage,
                'order' => $freshOrder,
                'payment' => $paymentPayload,
                'whatsapp_url' => ($isOnlinePayment && ($paymentPayload['status'] ?? null) !== OrderPixPaymentService::STATUS_PAID)
                    ? null
                    : ($whatsappUrl ?: $freshOrder->whatsapp_url),
                'store_whatsapp_number' => $store->whatsapp_number,
                'customer' => $user->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'role',
                    'address',
                    'address_number',
                    'district',
                    'address_complement',
                ]),
                'user' => $user->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'role',
                    'address',
                    'address_number',
                    'district',
                    'address_complement',
                ]),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Erro ao finalizar pedido.',
                'details' => config('app.debug') ? $e->getMessage() : null,
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
                    'options' => $options->isNotEmpty() ? $options->values()->all() : null,
                ];
            }

            return [$itemsTotal, $itemsToCreate];
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar os itens do pedido: ' . $e->getMessage());
        }
    }

    private function resolveDeliveryArea(Store $store, array $validated): ?DeliveryArea
    {
        if (!$store->canUseFeature('delivery_areas')) {
            return null;
        }

        $areas = $store->deliveryAreas()
            ->where('is_active', true)
            ->get();

        if ($areas->isEmpty()) {
            return null;
        }

        $area = DeliveryAreaMatcher::find(
            $areas,
            !empty($validated['delivery_area_id']) ? (int) $validated['delivery_area_id'] : null,
            $validated['district'] ?? null,
            $validated['city'] ?? null
        );

        if (!$area) {
            throw new \Exception('Não entregamos nessa área. Escolha uma região atendida pela loja.');
        }

        return $area;
    }

    private function getValidCoupon(int $storeId, float $subtotal, ?int $couponId = null, ?string $code = null): Coupon
    {
        try {
            $store = Store::query()->with('plan')->findOrFail($storeId);

            if (! $store->canUseFeature('coupons')) {
                throw new \Exception('Cupons não estão disponíveis para esta loja.');
            }

            $query = Coupon::where('store_id', $storeId);

            if ($couponId) {
                $query->where('id', $couponId);
            } else {
                $query->where('code', strtoupper(trim((string) $code)));
            }

            $coupon = $query->first();

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

    private function syncCustomerProfile(User $user, array $validated): User
    {
        $phone = $this->normalizeBrazilPhone($validated['customer_phone']);

        $profileData = [
            'name' => $validated['customer_name'],
            'phone' => $phone,
        ];

        if ($validated['fulfillment_type'] === 'delivery') {
            $profileData['address'] = $validated['address'] ?? null;
            $profileData['address_number'] = $validated['address_number'] ?? null;
            $profileData['district'] = $validated['district'] ?? null;
            $profileData['address_complement'] = $validated['address_complement'] ?? null;
        }

        $user->forceFill($profileData)->save();

        return $user->fresh();
    }

    private function findOrCreateGuestUser(string $name, string $phone): User
    {
        $digits = $this->normalizeBrazilPhone($phone);
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
        $streetLine = StreetAddress::merge(
            $data['address'] ?? null,
            $data['address_number'] ?? null
        );

        return collect([
            $streetLine ?: null,
            $data['address_complement'] ?? null,
        ])->filter()->implode(', ');
    }

    private function normalizeDeliveryAddress(array $validated): array
    {
        if (($validated['fulfillment_type'] ?? null) !== 'delivery') {
            return $validated;
        }

        $normalized = StreetAddress::normalize(
            $validated['address'] ?? null,
            $validated['address_number'] ?? null
        );

        $validated['address'] = $normalized['street'] ?: $normalized['line'];
        $validated['address_number'] = $normalized['number'];

        return $validated;
    }

    private function resolveDeliveryDistrict(array $validated, ?DeliveryArea $deliveryArea): array
    {
        if (filled($validated['district'] ?? null)) {
            return $validated;
        }

        if ($deliveryArea) {
            $validated['district'] = $deliveryArea->district_name;
            $validated['city'] = $validated['city'] ?? $deliveryArea->city;
        }

        return $validated;
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value);
    }

    private function normalizeBrazilPhone(string $value): string
    {
        return BrazilPhone::normalize($value);
    }

    private function publicPixFailureDetails(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }

        if (str_contains($message, 'credenciais inválidas') || str_contains($message, 'Unauthorized')) {
            return 'Access Token do Mercado Pago inválido ou expirado. Revise em Recebimentos no painel.';
        }

        if (str_contains($message, 'QR Code') || str_contains($message, 'qr render')) {
            return 'Conta Mercado Pago sem Pix habilitado. Ative recebimentos Pix no painel do Mercado Pago.';
        }

        if (str_starts_with($message, 'Mercado Pago: ')) {
            return substr($message, strlen('Mercado Pago: '));
        }

        if (str_starts_with($message, 'Pagar.me: ')) {
            return substr($message, strlen('Pagar.me: '));
        }

        return null;
    }
}
