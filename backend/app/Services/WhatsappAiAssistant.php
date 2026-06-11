<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappAiAssistant
{
    public function canReply(Store $store): bool
    {
        return $store->canUseFeature('whatsapp_ai')
            && (bool) $store->whatsapp_ai_enabled
            && filled(config('services.openai.api_key'))
            && (bool) config('services.openai.enabled', true);
    }

    public function reply(Store $store, WhatsappSession $session, string $userMessage): ?string
    {
        if (! $this->canReply($store)) {
            return null;
        }

        if (! $this->withinRateLimit($store, $session->customer_phone)) {
            return 'Recebi muitas mensagens seguidas. Aguarde um pouco ou digite *4* para falar com atendente.';
        }

        try {
            $messages = $this->buildMessages($store, $session, $userMessage);
            $response = Http::timeout((int) config('services.openai.timeout', 20))
                ->retry(1, 300)
                ->withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->post($this->chatEndpoint(), [
                    'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                    'temperature' => 0.3,
                    'max_tokens' => 320,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                Log::warning('WhatsApp AI request failed', [
                    'store_id' => $store->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            return $this->sanitize($text) ?: null;
        } catch (Throwable $e) {
            Log::warning('WhatsApp AI exception', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildMessages(Store $store, WhatsappSession $session, string $userMessage): array
    {
        $historyLimit = (int) config('whatsapp.ai_max_history_messages', 6);
        $history = $session->messages()
            ->latest()
            ->limit($historyLimit)
            ->get()
            ->reverse()
            ->flatMap(function ($row) {
                $role = $row->direction === 'inbound' ? 'user' : 'assistant';

                return [['role' => $role, 'content' => $row->body]];
            })
            ->values()
            ->all();

        return array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($store)]],
            $history,
            [['role' => 'user', 'content' => $userMessage]]
        );
    }

    private function systemPrompt(Store $store): string
    {
        return implode("\n", [
            "Você é o assistente de WhatsApp da loja {$store->name}.",
            'Responda em português do Brasil, tom cordial e objetivo (máximo 3 parágrafos curtos).',
            'Use APENAS as informações do CONTEXTO. Nunca invente produtos, preços ou promoções.',
            'Se não souber, diga que não tem essa informação e indique o cardápio digital.',
            'Para fazer pedido, sempre envie o link do cardápio.',
            'Se o cliente quiser humano, diga para digitar 4.',
            '',
            'CONTEXTO:',
            $this->buildStoreContext($store),
        ]);
    }

    private function buildStoreContext(Store $store): string
    {
        return Cache::remember(
            "whatsapp.ai.context.{$store->id}",
            now()->addMinutes(5),
            fn () => $this->composeStoreContext($store)
        );
    }

    private function composeStoreContext(Store $store): string
    {
        $lines = [
            'Nome: '.$store->name,
            'Descrição: '.trim((string) ($store->description ?: '—')),
            'Aberto agora: '.($store->is_open_now ? 'Sim' : 'Não'),
            'Status: '.(data_get($store->opening_status, 'message') ?: '—'),
            'Cardápio: '.rtrim((string) config('whatsapp.customer_app_url'), '/').'/'.$store->slug,
            'Formas de pagamento: '.implode(', ', $store->acceptedPaymentMethods()),
        ];

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(25)
            ->get(['name', 'price', 'description']);

        if ($products->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Produtos ativos:';
            $lines = array_merge($lines, $this->formatProducts($products));
        }

        $faq = trim((string) $store->whatsapp_ai_faq);

        if ($faq !== '') {
            $lines[] = '';
            $lines[] = 'FAQ da loja:';
            $lines[] = $faq;
        }

        if ($store->canUseFeature('delivery_areas')) {
            $areas = $store->deliveryAreas()->where('is_active', true)->limit(10)->get(['name', 'fee']);

            if ($areas->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Áreas de entrega:';
                foreach ($areas as $area) {
                    $fee = number_format((float) $area->fee, 2, ',', '.');
                    $lines[] = "- {$area->name}: taxa R$ {$fee}";
                }
            }
        }

        return implode("\n", $lines);
    }

    private function formatProducts(Collection $products): array
    {
        return $products->map(function (Product $product) {
            $price = number_format((float) $product->price, 2, ',', '.');

            return "- {$product->name} — R$ {$price}";
        })->all();
    }

    private function chatEndpoint(): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions';
    }

    private function sanitize(string $text): string
    {
        $clean = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        return mb_substr($clean, 0, 1200);
    }

    private function withinRateLimit(Store $store, string $phone): bool
    {
        $key = sprintf(
            'whatsapp:ai:%d:%s:%s',
            $store->id,
            $phone,
            now()->format('YmdH')
        );

        $count = (int) Cache::get($key, 0);
        $limit = (int) config('whatsapp.ai_rate_limit_per_hour', 20);

        if ($count >= $limit) {
            return false;
        }

        Cache::put($key, $count + 1, now()->addHour());

        return true;
    }
}
