<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\PagarMeService;
use App\Services\WhatsappProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingController extends Controller
{
    private function billingValidationMessages(): array
    {
        return [
            'plan_id.required' => 'Selecione um plano válido.',
            'plan_id.exists' => 'O plano selecionado não está disponível.',
            'billing_email.required' => 'Informe o e-mail de cobrança.',
            'billing_email.email' => 'Informe um e-mail de cobrança válido.',
            'card_token.required' => 'Não foi possível validar o cartão. Tente novamente.',
            'holder_document.required' => 'Informe o CPF do titular.',
            'holder_document.min' => 'Informe um CPF válido.',
            'holder_name.required' => 'Informe o nome impresso no cartão.',
            'holder_phone.required' => 'Informe o WhatsApp do titular.',
            'holder_phone.min' => 'Informe um WhatsApp válido com DDD.',
            'number.required' => 'Informe o número do cartão.',
            'number.min' => 'Informe um número de cartão válido.',
            'exp_month.required' => 'Informe o mês de validade do cartão.',
            'exp_year.required' => 'Informe o ano de validade do cartão.',
            'cvv.required' => 'Informe o CVV do cartão.',
        ];
    }

    private function validationErrorResponse(ValidationException $exception)
    {
        $message = collect($exception->errors())->flatten()->first()
            ?: 'Verifique os dados de pagamento e tente novamente.';

        return response()->json([
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);
    }

    public function pagarMeWebhook(Request $request, PagarMeService $pagarMe)
    {
        try {
            $rawBody = $request->getContent();
            $signature = $request->header('x-hub-signature-256')
                ?? $request->header('x-hub-signature');

            if (! $pagarMe->verifyWebhookRequest(
                $rawBody,
                $signature,
                $request->getUser(),
                $request->getPassword()
            )) {
                Log::warning('Pagar.me webhook rejeitado: assinatura inválida', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'message' => 'Assinatura do webhook inválida.',
                ], 401);
            }

            $eventType = (string) (
                $request->input('type')
                ?? $request->input('event')
                ?? $request->input('event_type')
            );

            Log::info('Pagar.me webhook de assinatura recebido', [
                'event' => $eventType,
            ]);

            $payload = (array) (
                $request->input('data')
                ?? $request->input('subscription')
                ?? $request->input('charge')
                ?? $request->input('order')
                ?? []
            );

            if (! str_contains($eventType, 'subscription') || empty($payload)) {
                return response()->json([
                    'message' => 'Webhook ignorado.',
                    'event' => $eventType,
                ]);
            }

            return $this->processPagarMeSubscriptionWebhook($pagarMe, $payload, $eventType);
        } catch (Throwable $e) {
            Log::error('Erro ao processar webhook Pagar.me', [
                'error' => $e->getMessage(),
                'body' => $request->all(),
            ]);

            return response()->json([
                'message' => 'Erro ao processar webhook Pagar.me.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function pagarMeStatus(PagarMeService $pagarMe)
    {
        try {
            return response()->json([
                'pagarme' => $pagarMe->configurationStatus(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao verificar Pagar.me.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function pagarMeToken(Request $request, PagarMeService $pagarMe)
    {
        try {
            $validated = $request->validate([
                'number' => ['required', 'string', 'min:13', 'max:19'],
                'holder_name' => ['required', 'string', 'max:255'],
                'holder_document' => ['required', 'string', 'min:11', 'max:14'],
                'exp_month' => ['required', 'integer', 'min:1', 'max:12'],
                'exp_year' => ['required', 'integer', 'min:24', 'max:2099'],
                'cvv' => ['required', 'string', 'min:3', 'max:4'],
            ], $this->billingValidationMessages());

            $token = $pagarMe->createCardToken($validated);

            return response()->json([
                'token' => $token,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao tokenizar cartão.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function pagarMeSubscription(Request $request, PagarMeService $pagarMe)
    {
        $store = null;

        try {
            $validated = $request->validate([
                'plan_id' => ['required', 'integer', 'exists:plans,id'],
                'billing_email' => ['required', 'email'],
                'card_token' => ['required', 'string', 'max:255'],
                'holder_document' => ['required', 'string', 'min:11', 'max:14'],
                'holder_name' => ['required', 'string', 'max:255'],
                'holder_phone' => ['required', 'string', 'min:10', 'max:20'],
            ], $this->billingValidationMessages());

            $user = $request->user();
            $activeStore = $request->attributes->get('merchant_store');
            $store = $activeStore?->matrizStore();

            if (! $store || ! $user->ownsStore($store)) {
                return response()->json([
                    'message' => 'Apenas o dono da loja matriz pode gerenciar assinaturas.',
                ], 403);
            }

            $store->load(['plan', 'user']);

            $plan = Plan::query()
                ->whereKey($validated['plan_id'])
                ->where('is_active', true)
                ->where('is_visible', true)
                ->firstOrFail();

            $pagarMe->validatePlanUpgrade($store, $plan);

            $subscription = DB::transaction(function () use ($store, $plan, $validated, $pagarMe) {
                $store->update([
                    'billing_email' => $validated['billing_email'],
                ]);

                $subscription = $pagarMe->createSubscription(
                    $store->fresh(['user', 'plan']),
                    $plan,
                    $validated['card_token'],
                    $validated['billing_email'],
                    $validated['holder_document'],
                    $validated['holder_name'],
                    $validated['holder_phone']
                );

                $subscriptionStatus = data_get($subscription, 'status');

                $store->update([
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->slug,
                    'subscription_status' => 'active',
                    'subscription_ends_at' => now()->addMonth(),
                    'complimentary_until' => null,
                    'complimentary_reason' => null,
                    'pagarme_customer_id' => data_get($subscription, 'customer_id'),
                    'pagarme_subscription_id' => data_get($subscription, 'id'),
                    'pagarme_subscription_status' => $subscriptionStatus,
                ]);

                $store->refresh();
                $store->syncBranchesSubscriptionFromMatriz();

                return $subscription;
            });

            app(WhatsappProvisioningService::class)->queueProvisioningForMatriz($store->fresh(['plan', 'branches.plan']));

            return response()->json([
                'message' => 'Assinatura criada com sucesso.',
                'subscription_id' => data_get($subscription, 'id'),
                'status' => data_get($subscription, 'status'),
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'gateway' => 'pagarme',
                'environment' => config('services.pagarme.environment', 'sandbox'),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            Log::warning('Erro ao criar assinatura Pagar.me', [
                'store_id' => $store->id ?? null,
                'plan_id' => $request->input('plan_id'),
                'error' => $e->getMessage(),
            ]);

            $message = trim($e->getMessage());

            return response()->json([
                'message' => $message !== '' ? $message : 'Erro ao criar assinatura Pagar.me.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    private function processPagarMeSubscriptionWebhook(PagarMeService $pagarMe, array $payload, string $eventType)
    {
        $subscriptionId = (string) data_get($payload, 'id');

        if (blank($subscriptionId)) {
            return response()->json([
                'message' => 'Webhook de assinatura sem ID.',
                'event' => $eventType,
            ], 422);
        }

        $subscription = $pagarMe->getSubscription($subscriptionId);
        $reference = $pagarMe->parseReference($subscription);
        $subscriptionStatus = data_get($subscription, 'status');
        $localStatus = $pagarMe->mapSubscriptionStatus($subscriptionStatus);

        $shouldActivate = $pagarMe->shouldActivatePlan($subscriptionStatus);
        $webhookSkipped = false;
        $activatedStore = null;

        DB::transaction(function () use ($reference, $subscription, $subscriptionId, $subscriptionStatus, $localStatus, $pagarMe, $shouldActivate, &$webhookSkipped, &$activatedStore) {
            $store = Store::query()
                ->whereKey($reference['store_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($reference['plan_id'])
                ->where('is_active', true)
                ->firstOrFail();

            if (! $shouldActivate) {
                if (in_array($subscriptionStatus, ['canceled', 'failed'], true)) {
                    $store->applyPlatformSubscriptionCancellation($subscriptionStatus);
                    $webhookSkipped = true;

                    return;
                }

                $graceEndsAt = $store->subscription_grace_ends_at;

                if ($localStatus === 'past_due') {
                    $subscriptionExpired = filled($store->subscription_ends_at)
                        && now()->gt($store->subscription_ends_at);

                    $graceEndsAt = $subscriptionExpired
                        ? ($graceEndsAt ?? now()->addDays(PlatformSetting::paymentGraceDays()))
                        : null;
                } elseif ($localStatus === 'active') {
                    $graceEndsAt = null;
                }

                $store->update([
                    'pagarme_subscription_id' => $subscriptionId,
                    'pagarme_subscription_status' => $subscriptionStatus,
                    'pagarme_customer_id' => data_get($subscription, 'customer.id', $store->pagarme_customer_id),
                    'subscription_status' => $localStatus,
                    'subscription_grace_ends_at' => $graceEndsAt,
                ]);

                $store->refresh();
                $store->syncBranchesSubscriptionFromMatriz();

                $webhookSkipped = true;

                return;
            }

            $updates = [
                'pagarme_subscription_id' => $subscriptionId,
                'pagarme_subscription_status' => $subscriptionStatus,
                'pagarme_customer_id' => data_get($subscription, 'customer.id', $store->pagarme_customer_id),
                'subscription_status' => 'active',
                'plan_id' => $plan->id,
                'plan_type' => $plan->slug,
                'subscription_ends_at' => now()->addMonth(),
                'subscription_grace_ends_at' => null,
                'complimentary_until' => null,
                'complimentary_reason' => null,
            ];

            $store->update($updates);
            $store->refresh();
            $store->syncBranchesSubscriptionFromMatriz();
            $activatedStore = $store->fresh(['plan', 'branches.plan']);
        });

        if ($activatedStore) {
            app(WhatsappProvisioningService::class)->queueProvisioningForMatriz($activatedStore);
        }

        if ($webhookSkipped) {
            return response()->json([
                'message' => 'Webhook recebido, assinatura ainda não ativa.',
                'subscription_id' => $subscriptionId,
                'status' => $subscriptionStatus,
                'event' => $eventType,
            ]);
        }

        return response()->json([
            'message' => 'Assinatura processada com sucesso.',
            'subscription_id' => $subscriptionId,
            'status' => $subscriptionStatus,
            'event' => $eventType,
        ]);
    }
}
