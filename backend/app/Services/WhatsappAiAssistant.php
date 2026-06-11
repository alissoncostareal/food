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
    public function provider(): string
    {
        return strtolower((string) config('whatsapp.ai_provider', 'gemini'));
    }

    public function providerLabel(): string
    {
        return match ($this->provider()) {
            'openai' => 'OpenAI',
            default => 'Google Gemini',
        };
    }

    public function isConfigured(): bool
    {
        return match ($this->provider()) {
            'openai' => filled(config('services.openai.api_key'))
                && (bool) config('services.openai.enabled', true),
            default => filled(config('services.gemini.api_key'))
                && (bool) config('services.gemini.enabled', true),
        };
    }

    public function canReply(Store $store): bool
    {
        return $store->whatsappAiActive()
            && $this->isConfigured();
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
            $text = match ($this->provider()) {
                'openai' => $this->replyWithOpenAi($store, $session, $userMessage),
                default => $this->replyWithGemini($store, $session, $userMessage),
            };

            return $this->sanitize((string) ($text ?? '')) ?: null;
        } catch (Throwable $e) {
            Log::warning('WhatsApp AI exception', [
                'store_id' => $store->id,
                'provider' => $this->provider(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function replyWithOpenAi(Store $store, WhatsappSession $session, string $userMessage): ?string
    {
        $messages = $this->buildOpenAiMessages($store, $session, $userMessage);

        $response = Http::timeout((int) config('services.openai.timeout', 20))
            ->retry(1, 300)
            ->withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->post($this->openAiChatEndpoint(), [
                'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.3,
                'max_tokens' => 320,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            $this->logProviderFailure($store, $response->status(), $response->body());

            return null;
        }

        return trim((string) data_get($response->json(), 'choices.0.message.content', ''));
    }

    private function replyWithGemini(Store $store, WhatsappSession $session, string $userMessage): ?string
    {
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $apiKey = (string) config('services.gemini.api_key');
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );

        $contents = $this->buildGeminiContents($session, $userMessage);

        $response = Http::timeout((int) config('services.gemini.timeout', 20))
            ->retry(1, 300)
            ->acceptJson()
            ->post($url.'?key='.urlencode($apiKey), [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $this->systemPrompt($store)],
                    ],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 320,
                ],
            ]);

        if ($response->failed()) {
            $this->logProviderFailure($store, $response->status(), $response->body());

            return null;
        }

        return trim((string) data_get(
            $response->json(),
            'candidates.0.content.parts.0.text',
            ''
        ));
    }

    private function buildOpenAiMessages(Store $store, WhatsappSession $session, string $userMessage): array
    {
        $history = $this->conversationHistory($session);

        if ($history === []) {
            $history = [['role' => 'user', 'content' => trim($userMessage)]];
        }

        return array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($store)]],
            $history
        );
    }

    private function buildGeminiContents(WhatsappSession $session, string $userMessage): array
    {
        $contents = collect($this->conversationHistory($session))
            ->map(function (array $message) {
                $role = $message['role'] === 'assistant' ? 'model' : 'user';

                return [
                    'role' => $role,
                    'parts' => [
                        ['text' => $message['content']],
                    ],
                ];
            })
            ->values()
            ->all();

        if ($contents !== []) {
            return $contents;
        }

        return [[
            'role' => 'user',
            'parts' => [
                ['text' => trim($userMessage)],
            ],
        ]];
    }

    private function conversationHistory(WhatsappSession $session): array
    {
        $historyLimit = (int) config('whatsapp.ai_max_history_messages', 6);

        return $session->messages()
            ->latest()
            ->limit($historyLimit)
            ->get()
            ->reverse()
            ->map(function ($row) {
                return [
                    'role' => $row->direction === 'inbound' ? 'user' : 'assistant',
                    'content' => $row->body,
                ];
            })
            ->values()
            ->all();
    }

    private function systemPrompt(Store $store): string
    {
        return implode("\n", [
            "Você é o assistente de WhatsApp da loja {$store->name}.",
            'Responda em português do Brasil, tom cordial e objetivo (máximo 3 parágrafos curtos).',
            'Use APENAS as informações do CONTEXTO (incluindo as informações da loja cadastradas pelo dono). Nunca invente produtos, preços ou promoções.',
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
            'Cardápio: '.$store->menuUrl(),
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
            $lines[] = 'Informações da loja (cadastradas pelo dono):';
            $lines[] = $faq;
        }

        if ($store->canUseFeature('delivery_areas')) {
            $areas = $store->deliveryAreas()->where('is_active', true)->limit(10)->get(['district_name', 'fee']);

            if ($areas->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Áreas de entrega:';
                foreach ($areas as $area) {
                    $fee = number_format((float) $area->fee, 2, ',', '.');
                    $lines[] = "- {$area->district_name}: taxa R$ {$fee}";
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

    private function openAiChatEndpoint(): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions';
    }

    private function sanitize(string $text): string
    {
        $clean = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        return mb_substr($clean, 0, 1200);
    }

    private function logProviderFailure(Store $store, int $status, string $body): void
    {
        Log::warning('WhatsApp AI request failed', [
            'store_id' => $store->id,
            'provider' => $this->provider(),
            'status' => $status,
            'body' => mb_substr($body, 0, 500),
        ]);
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
