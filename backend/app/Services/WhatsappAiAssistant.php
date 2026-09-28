<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
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
            'anthropic' => 'Anthropic Claude',
            'groq' => 'Groq',
            default => 'Google Gemini',
        };
    }

    public function isConfigured(): bool
    {
        return match ($this->provider()) {
            'openai' => filled(config('prism.providers.openai.api_key', config('services.openai.api_key')))
                && (bool) config('services.openai.enabled', true),
            'anthropic' => filled(config('prism.providers.anthropic.api_key')),
            'groq' => filled(config('prism.providers.groq.api_key')),
            default => filled(config('prism.providers.gemini.api_key', config('services.gemini.api_key')))
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
            $providerEnum = match ($this->provider()) {
                'openai' => Provider::OpenAI,
                'anthropic' => Provider::Anthropic,
                'groq' => Provider::Groq,
                default => Provider::Gemini,
            };

            $model = match ($this->provider()) {
                'openai' => (string) config('services.openai.model', 'gpt-4o-mini'),
                default => (string) config('services.gemini.model', 'gemini-2.5-flash'),
            };

            $messages = $this->buildPrismMessages($session, $userMessage);
            $tools = $this->buildTools($store, $session);

            $response = Prism::text()
                ->using($providerEnum, $model)
                ->withSystemPrompt($this->systemPrompt($store))
                ->withMessages($messages)
                ->withTools($tools)
                ->withMaxTokens(350)
                ->withTemperature(0.3)
                ->asText();

            $text = $response->text;

            return $this->sanitize((string) ($text ?? '')) ?: null;
        } catch (Throwable $e) {
            Log::warning('WhatsApp AI exception via Prism', [
                'store_id' => $store->id,
                'provider' => $this->provider(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildTools(Store $store, WhatsappSession $session): array
    {
        $orderTool = (new Tool())
            ->as('consultar_pedido')
            ->for('Consulta o status, valor e detalhes de um pedido do cliente na loja pelo código ou número informado')
            ->withStringParameter('codigo_pedido', 'Código, número ou ID do pedido')
            ->using(function (string $codigo_pedido) use ($store, $session) {
                $cleanId = (int) preg_replace('/\D/', '', $codigo_pedido);

                $order = Order::query()
                    ->where('store_id', $store->id)
                    ->where(function ($q) use ($cleanId, $codigo_pedido, $session) {
                        if ($cleanId > 0) {
                            $q->where('id', $cleanId);
                        }
                        $q->orWhere('display_number', trim($codigo_pedido))
                          ->orWhere('customer_phone', $session->customer_phone);
                    })
                    ->latest()
                    ->first();

                if (! $order) {
                    return 'Não encontramos pedido com este código para esta loja.';
                }

                $statusLabel = match ((string) $order->status) {
                    'pending' => 'Aguardando confirmação',
                    'preparing' => 'Em preparo',
                    'ready' => 'Pronto para entrega/retirada',
                    'out_for_delivery' => 'Saiu para entrega',
                    'delivered' => 'Entregue',
                    'completed' => 'Concluído',
                    'canceled', 'cancelled' => 'Cancelado',
                    default => $order->status,
                };

                $total = number_format((float) $order->total, 2, ',', '.');
                $numero = $order->display_number ?: $order->id;

                return "Pedido #{$numero}: Status: {$statusLabel}, Total: R$ {$total}, Feito em: {$order->created_at->format('d/m H:i')}.";
            });

        $deliveryTool = (new Tool())
            ->as('consultar_taxa_entrega')
            ->for('Consulta se a loja realiza entregas em determinado bairro ou localidade e qual o valor da taxa')
            ->withStringParameter('bairro', 'Nome do bairro ou região informada pelo cliente')
            ->using(function (string $bairro) use ($store) {
                if (! $store->canUseFeature('delivery_areas')) {
                    return "Para consultar taxas de entrega e bairros atendidos, acesse nosso cardápio digital: {$store->menuUrl()}";
                }

                $term = trim($bairro);
                $area = $store->deliveryAreas()
                    ->where('is_active', true)
                    ->where('district_name', 'LIKE', "%{$term}%")
                    ->first();

                if (! $area) {
                    return "Não localizamos o bairro '{$term}' nas áreas cadastradas. Consulte o cardápio para confirmar a cobertura: {$store->menuUrl()}";
                }

                $fee = number_format((float) $area->fee, 2, ',', '.');

                return "Para o bairro {$area->district_name}, a taxa de entrega é R$ {$fee}.";
            });

        return [$orderTool, $deliveryTool];
    }

    private function buildPrismMessages(WhatsappSession $session, string $userMessage): array
    {
        $history = $this->conversationHistory($session);
        $messages = [];

        foreach ($history as $msg) {
            if ($msg['role'] === 'assistant') {
                $messages[] = new AssistantMessage($msg['content']);
            } else {
                $messages[] = new UserMessage($msg['content']);
            }
        }

        if ($messages === []) {
            $messages[] = new UserMessage(trim($userMessage));
        }

        return $messages;
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
            'Use APENAS as informações do CONTEXTO (incluindo as informações da loja cadastradas pelo dono) ou consulte as ferramentas disponíveis quando o cliente perguntar sobre pedidos ou bairros de entrega. Nunca invente produtos, preços ou promoções.',
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
