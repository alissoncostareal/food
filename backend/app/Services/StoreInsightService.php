<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class StoreInsightService
{
    public function generate(
        int $storeId,
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName = null,
        array $context = [],
        bool $forceRefresh = false
    ): array {
        $cacheKey = "store.insights.{$storeId}";
        $cacheTtl = (int) config('services.gemini.insights_cache_ttl', 1800);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        if ($cacheTtl > 0 && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->generateFresh(
            $stats,
            $topProducts,
            $ordersByWeekday,
            $ordersByHour,
            $delayedOrders,
            $storeName,
            $context
        );

        if ($cacheTtl > 0) {
            Cache::put($cacheKey, $result, $cacheTtl);
        }

        return $result;
    }

    private function generateFresh(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName,
        array $context
    ): array {
        $fallbackItems = $this->buildFallbackInsights(
            $stats,
            $topProducts,
            $ordersByWeekday,
            $ordersByHour,
            $delayedOrders,
            $context
        );

        if (! $this->isConfigured()) {
            return $this->wrapResult($fallbackItems, 'rules');
        }

        try {
            $response = $this->requestPrismInsights(
                $stats,
                $topProducts,
                $ordersByWeekday,
                $ordersByHour,
                $delayedOrders,
                $storeName,
                $context
            );

            if ($response === null) {
                return $this->wrapResult($fallbackItems, 'rules');
            }

            $items = $this->normalizeInsights($response);

            if ($items === []) {
                return $this->wrapResult($fallbackItems, 'rules');
            }

            return $this->wrapResult($items, 'gemini');
        } catch (Exception $e) {
            Log::warning('Gemini Prism insights generation failed.', [
                'message' => $e->getMessage(),
            ]);

            return $this->wrapResult($fallbackItems, 'rules');
        }
    }

    private function requestPrismInsights(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName,
        array $context
    ): mixed {
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        $dataset = $this->buildDataset(
            $stats,
            $topProducts,
            $ordersByWeekday,
            $ordersByHour,
            $delayedOrders,
            $storeName,
            $context
        );

        $response = Prism::structured()
            ->using(Provider::Gemini, $model)
            ->withSystemPrompt($this->systemPrompt())
            ->withPrompt(json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->withSchema($this->prismSchema())
            ->withTemperature(0.3)
            ->asStructured();

        return $response->structured;
    }

    private function prismSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'store_insights_response',
            description: 'Insights estratégicos para o restaurante',
            properties: [
                new ArraySchema(
                    name: 'insights',
                    description: 'Lista de 3 a 5 insights práticos e acionáveis',
                    items: new ObjectSchema(
                        name: 'insight_item',
                        description: 'Detalhes do insight',
                        properties: [
                            new StringSchema('title', 'Título curto do insight'),
                            new StringSchema('description', 'Explicação com contexto dos números'),
                            new StringSchema('type', 'Tipo do insight', ['sales', 'timing', 'menu', 'operation', 'growth']),
                            new StringSchema('priority', 'Nível de prioridade', ['high', 'medium', 'low']),
                            new StringSchema('action', 'Ação prática recomendada para o lojista'),
                        ],
                        requiredFields: ['title', 'description', 'type', 'priority', 'action']
                    )
                ),
            ],
            requiredFields: ['insights']
        );
    }

    private function wrapResult(array $items, string $source): array
    {
        return [
            'items' => array_slice($items, 0, 5),
            'meta' => [
                'source' => $source,
                'generated_at' => now()->toIso8601String(),
                'model' => $source === 'gemini' ? config('services.gemini.model') : null,
            ],
        ];
    }

    private function isConfigured(): bool
    {
        return (filled(config('prism.providers.gemini.api_key')) || filled(config('services.gemini.api_key')))
            && (bool) config('services.gemini.enabled', true);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Você é um consultor de crescimento para restaurantes e delivery no Brasil.',
            'Analise os dados agregados e gere de 3 a 5 insights práticos em português do Brasil.',
            'Cada insight deve ser específico aos números recebidos — cite dias, horários ou produtos quando relevante.',
            'Priorize: operação (atrasos/pendências), timing (horários/dias), cardápio, vendas e crescimento.',
            'Tom direto, amigável e acionável. Não mencione IA ou modelos.',
            'Não invente números que não estejam nos dados.',
            'Se houver cancelamentos ou queda de receita, sugira ação concreta.',
        ]);
    }

    private function buildDataset(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName,
        array $context
    ): array {
        return [
            'store' => [
                'name' => $storeName,
                'is_open_now' => (bool) ($context['store_is_open'] ?? false),
            ],
            'stats' => [
                'today_revenue' => round((float) ($stats['today']['revenue'] ?? 0), 2),
                'today_sales_count' => (int) ($stats['today']['sales_count'] ?? 0),
                'pending_now' => (int) ($stats['pending_now'] ?? 0),
                'monthly_revenue' => round((float) ($stats['monthly_revenue'] ?? 0), 2),
                'monthly_orders_count' => (int) ($stats['monthly_orders_count'] ?? 0),
                'average_ticket' => round((float) ($stats['average_ticket'] ?? 0), 2),
            ],
            'trends' => [
                'revenue_last_7_days' => round((float) ($context['revenue_last_7_days'] ?? 0), 2),
                'revenue_trend' => $context['revenue_trend'] ?? 'stable',
                'canceled_orders_30d' => (int) ($context['canceled_orders_30d'] ?? 0),
            ],
            'top_products' => $topProducts->take(5)->values()->all(),
            'sales_by_weekday' => $ordersByWeekday->take(7)->values()->all(),
            'sales_by_hour' => $ordersByHour->take(5)->values()->all(),
            'weakest_weekday' => $ordersByWeekday->sortBy('orders_count')->first(),
            'operations' => [
                'delayed_orders' => $delayedOrders,
                'delay_threshold_minutes' => 45,
            ],
        ];
    }

    private function normalizeInsights(mixed $structured): array
    {
        $rawList = is_array($structured)
            ? ($structured['insights'] ?? $structured)
            : (is_object($structured) && isset($structured->insights) ? (array) $structured->insights : []);

        if (! is_array($rawList)) {
            return [];
        }

        return collect($rawList)
            ->filter(fn ($insight) => is_array($insight) || is_object($insight))
            ->map(function ($insight) {
                $item = (array) $insight;

                return [
                    'title' => trim((string) ($item['title'] ?? '')),
                    'description' => trim((string) ($item['description'] ?? '')),
                    'type' => in_array(($item['type'] ?? ''), ['sales', 'timing', 'menu', 'operation', 'growth'], true)
                        ? $item['type']
                        : 'growth',
                    'priority' => in_array(($item['priority'] ?? ''), ['high', 'medium', 'low'], true)
                        ? $item['priority']
                        : 'medium',
                    'action' => filled($item['action'] ?? null) && trim((string) $item['action']) !== ''
                        ? trim((string) $item['action'])
                        : null,
                ];
            })
            ->filter(fn ($insight) => $insight['title'] !== '' && $insight['description'] !== '')
            ->sortBy(fn ($insight) => match ($insight['priority']) {
                'high' => 0,
                'medium' => 1,
                default => 2,
            })
            ->take(5)
            ->values()
            ->all();
    }

    private function buildFallbackInsights(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        array $context = []
    ): array {
        try {
            $insights = [];
            $bestDay = $ordersByWeekday->first();
            $bestHour = $ordersByHour->first();
            $topProduct = $topProducts->first();
            $weakestDay = $ordersByWeekday->sortBy('orders_count')->first();
            $canceled = (int) ($context['canceled_orders_30d'] ?? 0);
            $trend = (string) ($context['revenue_trend'] ?? 'stable');

            if ($delayedOrders > 0) {
                $insights[] = [
                    'title' => "{$delayedOrders} pedido(s) em possível atraso",
                    'description' => 'Priorize a cozinha e avise clientes com pedidos abertos há mais de 45 minutos.',
                    'type' => 'operation',
                    'priority' => 'high',
                    'action' => 'Abrir pedidos em aberto',
                ];
            }

            if (($stats['pending_now'] ?? 0) >= 3) {
                $insights[] = [
                    'title' => 'Fila de pedidos elevada',
                    'description' => "Você tem {$stats['pending_now']} pedidos aguardando. Revise tempos de preparo e disponibilidade de itens.",
                    'type' => 'operation',
                    'priority' => 'high',
                    'action' => 'Conferir fila agora',
                ];
            }

            if ($canceled > 0) {
                $insights[] = [
                    'title' => "{$canceled} cancelamento(s) no mês",
                    'description' => 'Revise estoque, horário de funcionamento e tempo de preparo para reduzir perdas.',
                    'type' => 'operation',
                    'priority' => 'medium',
                    'action' => 'Analisar motivos',
                ];
            }

            if ($bestDay) {
                $insights[] = [
                    'title' => "{$bestDay['label']} concentra mais pedidos",
                    'description' => "Foram {$bestDay['orders_count']} pedidos nesse dia nos últimos 30 dias. Reforce estoque e promoções.",
                    'type' => 'sales',
                    'priority' => 'medium',
                    'action' => 'Planejar campanha',
                ];
            }

            if ($bestHour) {
                $insights[] = [
                    'title' => "Pico às {$bestHour['label']}",
                    'description' => 'Prepare equipe e insumos antes desse horário. Teste combos rápidos para esse período.',
                    'type' => 'timing',
                    'priority' => 'medium',
                    'action' => 'Ajustar equipe',
                ];
            }

            if ($topProduct) {
                $insights[] = [
                    'title' => "{$topProduct['name']} lidera vendas",
                    'description' => 'Destaque no cardápio e crie combos com adicionais para elevar o ticket médio.',
                    'type' => 'menu',
                    'priority' => 'low',
                    'action' => 'Destacar no cardápio',
                ];
            }

            if ($weakestDay && $bestDay && $weakestDay['weekday'] !== $bestDay['weekday']) {
                $insights[] = [
                    'title' => "{$weakestDay['label']} vende menos",
                    'description' => 'Teste cupom ou promoção nesse dia para equilibrar a operação da semana.',
                    'type' => 'growth',
                    'priority' => 'low',
                    'action' => 'Criar promoção',
                ];
            }

            if ($trend === 'down') {
                $insights[] = [
                    'title' => 'Receita caiu na última semana',
                    'description' => 'Compare cardápio, taxa de entrega e horários abertos. Uma campanha curta pode reativar demanda.',
                    'type' => 'growth',
                    'priority' => 'high',
                    'action' => 'Ver relatórios',
                ];
            }

            if (($stats['average_ticket'] ?? 0) > 0 && count($insights) < 5) {
                $insights[] = [
                    'title' => 'Oportunidade de ticket médio',
                    'description' => 'Ticket médio de '.number_format((float) $stats['average_ticket'], 2, ',', '.').'. Sugira bebida ou adicional nos itens campeões.',
                    'type' => 'growth',
                    'priority' => 'low',
                    'action' => 'Montar combos',
                ];
            }

            return collect($insights)
                ->unique('title')
                ->sortBy(fn ($insight) => match ($insight['priority']) {
                    'high' => 0,
                    'medium' => 1,
                    default => 2,
                })
                ->take(5)
                ->values()
                ->all();
        } catch (Exception) {
            return [];
        }
    }
}
