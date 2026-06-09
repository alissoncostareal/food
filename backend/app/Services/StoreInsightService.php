<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreInsightService
{
    public function generate(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName = null
    ): array {
        $fallbackInsights = $this->buildFallbackInsights(
            $stats,
            $topProducts,
            $ordersByWeekday,
            $ordersByHour,
            $delayedOrders
        );

        if (!$this->isConfigured()) {
            return $fallbackInsights;
        }

        try {
            $response = Http::timeout((int) config('services.openai.timeout', 12))
                ->retry(1, 300)
                ->withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->post($this->endpoint(), $this->payload(
                    $stats,
                    $topProducts,
                    $ordersByWeekday,
                    $ordersByHour,
                    $delayedOrders,
                    $storeName
                ));

            if (!$response->successful()) {
                Log::warning('OpenAI insights request failed.', [
                    'status' => $response->status(),
                    'body' => $response->json() ?: $response->body(),
                ]);

                return $fallbackInsights;
            }

            $insights = $this->normalizeInsights($this->extractText($response->json()));

            return $insights !== [] ? $insights : $fallbackInsights;
        } catch (Exception $e) {
            Log::warning('OpenAI insights generation failed.', [
                'message' => $e->getMessage(),
            ]);

            return $fallbackInsights;
        }
    }

    private function isConfigured(): bool
    {
        return filled(config('services.openai.api_key'))
            && filled(config('services.openai.model'))
            && (bool) config('services.openai.enabled', true);
    }

    private function endpoint(): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/') . '/responses';
    }

    private function payload(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders,
        ?string $storeName
    ): array {
        return [
            'model' => config('services.openai.model', 'gpt-5-mini'),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => implode(' ', [
                                'Você é um consultor de crescimento para restaurantes delivery.',
                                'Gere sugestões curtas, práticas e acionáveis em português do Brasil.',
                                'Use apenas os dados agregados enviados.',
                                'Não invente números, não mencione IA e não prometa resultados garantidos.',
                            ]),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => json_encode([
                                'store' => [
                                    'name' => $storeName,
                                ],
                                'stats' => [
                                    'today_revenue' => $stats['today']['revenue'] ?? 0,
                                    'today_sales_count' => $stats['today']['sales_count'] ?? 0,
                                    'pending_now' => $stats['pending_now'] ?? 0,
                                    'monthly_revenue' => $stats['monthly_revenue'] ?? 0,
                                    'monthly_orders_count' => $stats['monthly_orders_count'] ?? 0,
                                    'average_ticket' => $stats['average_ticket'] ?? 0,
                                ],
                                'top_products' => $topProducts->take(5)->values(),
                                'sales_by_weekday' => $ordersByWeekday->take(7)->values(),
                                'sales_by_hour' => $ordersByHour->take(5)->values(),
                                'operations' => [
                                    'delayed_orders' => $delayedOrders,
                                    'delay_threshold_minutes' => 45,
                                ],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'store_growth_insights',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['insights'],
                        'properties' => [
                            'insights' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 5,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['title', 'description', 'type'],
                                    'properties' => [
                                        'title' => [
                                            'type' => 'string',
                                            'maxLength' => 80,
                                        ],
                                        'description' => [
                                            'type' => 'string',
                                            'maxLength' => 220,
                                        ],
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => ['sales', 'timing', 'menu', 'operation', 'growth'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function extractText(?array $response): string
    {
        if (!$response) {
            return '';
        }

        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        return '';
    }

    private function normalizeInsights(string $text): array
    {
        $decoded = json_decode($text, true);

        if (!is_array($decoded) || !isset($decoded['insights']) || !is_array($decoded['insights'])) {
            return [];
        }

        return collect($decoded['insights'])
            ->filter(fn ($insight) => is_array($insight))
            ->map(fn ($insight) => [
                'title' => trim((string) ($insight['title'] ?? '')),
                'description' => trim((string) ($insight['description'] ?? '')),
                'type' => in_array(($insight['type'] ?? ''), ['sales', 'timing', 'menu', 'operation', 'growth'], true)
                    ? $insight['type']
                    : 'growth',
            ])
            ->filter(fn ($insight) => $insight['title'] !== '' && $insight['description'] !== '')
            ->take(5)
            ->values()
            ->all();
    }

    private function buildFallbackInsights(
        array $stats,
        Collection $topProducts,
        Collection $ordersByWeekday,
        Collection $ordersByHour,
        int $delayedOrders
    ): array {
        try {
            $insights = [];
            $bestDay = $ordersByWeekday->first();
            $bestHour = $ordersByHour->first();
            $topProduct = $topProducts->first();

            if ($bestDay) {
                $insights[] = [
                    'title' => "{$bestDay['label']} é seu dia mais forte",
                    'description' => "Nos últimos 30 dias, esse dia concentrou {$bestDay['orders_count']} pedidos. Prepare estoque, equipe e campanhas para esse pico.",
                    'type' => 'sales',
                ];
            }

            if ($bestHour) {
                $insights[] = [
                    'title' => "Horário quente: {$bestHour['label']}",
                    'description' => 'Esse horário aparece entre os maiores volumes. Ative combos, cupons ou mensagens antes desse período.',
                    'type' => 'timing',
                ];
            }

            if ($topProduct) {
                $insights[] = [
                    'title' => "{$topProduct['name']} puxa vendas",
                    'description' => 'Use esse item como destaque no cardápio e teste adicionais para aumentar o ticket médio.',
                    'type' => 'menu',
                ];
            }

            if ($delayedOrders > 0) {
                $insights[] = [
                    'title' => "{$delayedOrders} pedido(s) em possível atraso",
                    'description' => 'Priorize esses pedidos ou avise o cliente para preservar a experiência.',
                    'type' => 'operation',
                ];
            }

            if (($stats['average_ticket'] ?? 0) > 0) {
                $insights[] = [
                    'title' => 'Aumente o ticket médio com combos',
                    'description' => 'Crie ofertas com bebida, sobremesa ou adicional nos itens mais vendidos.',
                    'type' => 'growth',
                ];
            }

            return array_slice($insights, 0, 5);
        } catch (Exception) {
            return [];
        }
    }
}
