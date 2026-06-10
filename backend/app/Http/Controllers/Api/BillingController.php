<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Throwable;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function mercadoPagoWebhook(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            \Log::info('Mercado Pago webhook recebido', [
                'body' => $request->all(),
                'query' => $request->query(),
                'headers' => [
                    'x-signature' => $request->header('x-signature'),
                    'x-request-id' => $request->header('x-request-id'),
                    'content-type' => $request->header('content-type'),
                ],
            ]);

            $paymentId =
                $request->input('data.id')
                ?? $request->input('id')
                ?? $request->query('data_id')
                ?? $request->query('id');

            $type =
                $request->input('type')
                ?? $request->input('topic')
                ?? $request->query('type')
                ?? $request->query('topic');

            $action =
                $request->input('action')
                ?? $request->query('action');

            $isPaymentEvent =
                $type === 'payment'
                || str_starts_with((string) $action, 'payment.');

            if (!$paymentId || !$isPaymentEvent) {
                \Log::info('Mercado Pago webhook ignorado', [
                    'payment_id' => $paymentId,
                    'type' => $type,
                    'action' => $action,
                ]);

                return response()->json([
                    'message' => 'Webhook ignorado.',
                    'payment_id' => $paymentId,
                    'type' => $type,
                    'action' => $action,
                ]);
            }

            $payment = $mercadoPago->getPayment($paymentId);

            \Log::info('Mercado Pago pagamento consultado', [
                'payment_id' => $paymentId,
                'status' => data_get($payment, 'status'),
                'external_reference' => data_get($payment, 'external_reference'),
            ]);

            if (data_get($payment, 'status') !== 'approved') {
                return response()->json([
                    'message' => 'Pagamento recebido, mas ainda não aprovado.',
                    'status' => data_get($payment, 'status'),
                ]);
            }

            $reference = $mercadoPago->parseExternalReference(
                data_get($payment, 'external_reference')
            );

            DB::transaction(function () use ($reference) {
                $store = Store::query()
                    ->whereKey($reference['store_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $plan = Plan::query()
                    ->whereKey($reference['plan_id'])
                    ->where('is_active', true)
                    ->firstOrFail();

                $store->update([
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->slug,
                    'subscription_status' => 'active',
                    'subscription_ends_at' => now()->addDays(30),
                    'complimentary_until' => null,
                    'complimentary_reason' => null,
                ]);
            });

            return response()->json([
                'message' => 'Plano atualizado com sucesso.',
                'payment_id' => $paymentId,
                'store_id' => $reference['store_id'],
                'plan_id' => $reference['plan_id'],
            ]);
        } catch (Throwable $e) {
            \Log::error('Erro ao processar webhook Mercado Pago', [
                'error' => $e->getMessage(),
                'body' => $request->all(),
                'query' => $request->query(),
            ]);

            return response()->json([
                'message' => 'Erro ao processar webhook Mercado Pago.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    public function mercadoPagoStatus(MercadoPagoService $mercadoPago)
    {
        try {
            return response()->json([
                'mercado_pago' => $mercadoPago->configurationStatus(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao verificar Mercado Pago.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function mercadoPagoCheckout(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            $validated = $request->validate([
                'plan_id' => ['required', 'integer', 'exists:plans,id'],
            ]);

            $user = $request->user();

            $store = $user->store()
                ->with(['plan', 'user'])
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($validated['plan_id'])
                ->firstOrFail();

            $checkout = $mercadoPago->createCheckoutPreference($store, $plan);

            return response()->json([
                'message' => 'Checkout criado com sucesso.',
                'preference_id' => $checkout['id'],
                'init_point' => $checkout['init_point'],
                'sandbox_init_point' => $checkout['sandbox_init_point'],
                'external_reference' => $checkout['external_reference'],
                'environment' => $checkout['environment'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao criar checkout Mercado Pago.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
