<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Store;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillingController extends Controller
{
    public function mercadoPagoWebhook(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            Log::info('Mercado Pago webhook recebido', [
                'body' => $request->all(),
                'query' => $request->query(),
                'headers' => [
                    'x-signature' => $request->header('x-signature'),
                    'x-request-id' => $request->header('x-request-id'),
                    'content-type' => $request->header('content-type'),
                ],
            ]);

            $resourceId =
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

            $isSubscriptionEvent =
                $type === 'preapproval'
                || str_starts_with((string) $action, 'preapproval.');

            if (!$resourceId || (!$isPaymentEvent && !$isSubscriptionEvent)) {
                Log::info('Mercado Pago webhook ignorado', [
                    'resource_id' => $resourceId,
                    'type' => $type,
                    'action' => $action,
                ]);

                return response()->json([
                    'message' => 'Webhook ignorado.',
                    'resource_id' => $resourceId,
                    'type' => $type,
                    'action' => $action,
                ]);
            }

            if ($isSubscriptionEvent) {
                return $this->processMercadoPagoSubscriptionWebhook(
                    $mercadoPago,
                    (string) $resourceId
                );
            }

            return $this->processMercadoPagoPaymentWebhook(
                $mercadoPago,
                (string) $resourceId
            );
        } catch (Throwable $e) {
            Log::error('Erro ao processar webhook Mercado Pago', [
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
                'details' => config('app.debug') ? $e->getMessage() : null,
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
                ->where('is_active', true)
                ->firstOrFail();

            $checkout = $mercadoPago->createCheckoutPreference($store, $plan);

            return response()->json([
                'message' => 'Checkout criado com sucesso.',
                'preference_id' => data_get($checkout, 'id'),
                'init_point' => data_get($checkout, 'init_point'),
                'sandbox_init_point' => data_get($checkout, 'sandbox_init_point'),
                'external_reference' => data_get($checkout, 'external_reference'),
                'environment' => data_get($checkout, 'environment'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao criar checkout Mercado Pago.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function mercadoPagoSubscription(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            $validated = $request->validate([
                'plan_id' => ['required', 'integer', 'exists:plans,id'],
                'billing_email' => ['required', 'email'],
            ]);

            $user = $request->user();

            $store = $user->store()
                ->with(['plan', 'user'])
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($validated['plan_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $store->update([
                'billing_email' => $validated['billing_email'],
            ]);

            $subscription = $mercadoPago->createSubscription($store, $plan);

            $store->update([
                'mercado_pago_preapproval_id' => data_get($subscription, 'id'),
                'mercado_pago_subscription_status' => data_get($subscription, 'status'),
            ]);

            return response()->json([
                'message' => 'Assinatura criada com sucesso.',
                'preapproval_id' => data_get($subscription, 'id'),
                'status' => data_get($subscription, 'status'),
                'init_point' => data_get($subscription, 'init_point'),
                'sandbox_init_point' => data_get($subscription, 'sandbox_init_point'),
                'external_reference' => data_get($subscription, 'external_reference'),
                'environment' => config('services.mercado_pago.environment', 'sandbox'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao criar assinatura Mercado Pago.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    private function processMercadoPagoSubscriptionWebhook(MercadoPagoService $mercadoPago, string $preapprovalId)
    {
        $subscription = $mercadoPago->getPreapproval($preapprovalId);

        Log::info('Mercado Pago assinatura consultada', [
            'preapproval_id' => $preapprovalId,
            'status' => data_get($subscription, 'status'),
            'external_reference' => data_get($subscription, 'external_reference'),
        ]);

        $reference = $mercadoPago->parseExternalReference(
            data_get($subscription, 'external_reference')
        );

        DB::transaction(function () use ($reference, $subscription, $preapprovalId) {
            $store = Store::query()
                ->whereKey($reference['store_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($reference['plan_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $subscriptionStatus = data_get($subscription, 'status');

            $updates = [
                'mercado_pago_preapproval_id' => $preapprovalId,
                'mercado_pago_subscription_status' => $subscriptionStatus,
            ];

            if (in_array($subscriptionStatus, ['authorized', 'active'], true)) {
                $updates = array_merge($updates, [
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->slug,
                    'subscription_status' => 'active',
                    'subscription_ends_at' => now()->addMonth(),
                    'complimentary_until' => null,
                    'complimentary_reason' => null,
                ]);
            }

            if (in_array($subscriptionStatus, [
                'cancelled',
                'cancelled_by_collector',
                'cancelled_by_payer',
                'paused',
            ], true)) {
                $updates['subscription_status'] = 'canceled';
            }

            $store->update($updates);
        });

        return response()->json([
            'message' => 'Assinatura processada com sucesso.',
            'preapproval_id' => $preapprovalId,
            'status' => data_get($subscription, 'status'),
        ]);
    }

    private function processMercadoPagoPaymentWebhook(MercadoPagoService $mercadoPago, string $paymentId)
    {
        $payment = $mercadoPago->getPayment($paymentId);

        Log::info('Mercado Pago pagamento consultado', [
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

        DB::transaction(function () use ($reference, $paymentId) {
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
                'subscription_ends_at' => now()->addMonth(),
                'mercado_pago_last_payment_id' => $paymentId,
                'mercado_pago_last_payment_at' => now(),
                'complimentary_until' => null,
                'complimentary_reason' => null,
            ]);
        });

        return response()->json([
            'message' => 'Pagamento processado com sucesso.',
            'payment_id' => $paymentId,
            'status' => data_get($payment, 'status'),
        ]);
    }
}
