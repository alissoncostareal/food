<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IfoodSandboxCatalogSeeder
{
    private const CATEGORY_NAME = 'Lanches PartiuMenu';

    private const ITEM_NAME = 'X-Burger PartiuMenu';

    public function __construct(
        private readonly IfoodService $ifood
    ) {}

    public function seed(Store $store): array
    {
        if (! $this->ifood->isSandbox()) {
            throw new RuntimeException('Seed de catálogo disponível apenas em sandbox.');
        }

        if (blank($store->ifood_merchant_id)) {
            throw new RuntimeException('Informe o Merchant ID da loja de teste antes de criar produtos.');
        }

        if (! $this->ifood->merchantAllowedInSandbox($store->ifood_merchant_id)) {
            throw new RuntimeException('Merchant ID inválido para sandbox. Use a loja de teste do Developer Portal.');
        }

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;
        $catalogId = $this->resolveDefaultCatalogId($token, $merchantId);

        $categoryId = $this->resolveOrCreateCategory($token, $merchantId, $catalogId);

        $ids = $this->resolveStableIds($merchantId);
        $reused = $this->findExistingItem($token, $merchantId, $catalogId, self::ITEM_NAME);

        $this->putJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/items",
            [
                'item' => [
                    'id' => $reused['item_id'] ?? $ids['item'],
                    'type' => 'DEFAULT',
                    'categoryId' => $categoryId,
                    'status' => 'AVAILABLE',
                    'price' => ['value' => 24.90],
                    'index' => 0,
                    'productId' => $ids['product'],
                ],
                'products' => [
                    [
                        'id' => $ids['product'],
                        'name' => self::ITEM_NAME,
                        'description' => 'Hambúrguer artesanal com queijo, alface e tomate.',
                        'optionGroups' => [
                            ['id' => $ids['group'], 'min' => 0, 'max' => 1],
                        ],
                    ],
                    [
                        'id' => $ids['fries_product'],
                        'name' => 'Batata frita',
                        'description' => 'Porção individual 200g',
                    ],
                ],
                'optionGroups' => [
                    [
                        'id' => $ids['group'],
                        'name' => 'Acompanhamento',
                        'status' => 'AVAILABLE',
                        'index' => 0,
                        'optionGroupType' => 'DEFAULT',
                        'optionIds' => [$ids['option']],
                    ],
                ],
                'options' => [
                    [
                        'id' => $ids['option'],
                        'status' => 'AVAILABLE',
                        'index' => 0,
                        'productId' => $ids['fries_product'],
                        'price' => ['value' => 8.00],
                    ],
                ],
            ]
        );

        return [
            'category_id' => $categoryId,
            'item_id' => $reused['item_id'] ?? $ids['item'],
            'reused_category' => $reused['reused_category'] ?? false,
            'reused_item' => filled($reused['item_id'] ?? null),
            'message' => 'Catálogo de teste pronto no iFood. Clique em Importar do iFood.',
        ];
    }

    private function resolveOrCreateCategory(string $token, string $merchantId, string $catalogId): string
    {
        $categories = $this->getJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories"
        );

        if (is_array($categories)) {
            foreach ($categories as $category) {
                if (strcasecmp(trim((string) data_get($category, 'name')), self::CATEGORY_NAME) === 0) {
                    return (string) (data_get($category, 'id') ?: data_get($category, 'categoryId'));
                }
            }
        }

        $categoryId = $this->resolveStableIds($merchantId)['category'];

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post(
                "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories",
                [
                    'id' => $categoryId,
                    'name' => self::CATEGORY_NAME,
                    'status' => 'AVAILABLE',
                    'template' => 'DEFAULT',
                    'sequence' => 0,
                ]
            );

        if ($response->successful()) {
            return $categoryId;
        }

        if ($response->status() === 409) {
            $existingId = $this->extractUuidFromConflict($response->json());

            if ($existingId) {
                return $existingId;
            }

            $categories = $this->getJson(
                $token,
                "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories"
            );

            foreach ((array) $categories as $category) {
                if (strcasecmp(trim((string) data_get($category, 'name')), self::CATEGORY_NAME) === 0) {
                    return (string) (data_get($category, 'id') ?: data_get($category, 'categoryId'));
                }
            }
        }

        throw new RuntimeException('Erro ao criar categoria no iFood: ' . $response->body());
    }

    private function findExistingItem(string $token, string $merchantId, string $catalogId, string $itemName): array
    {
        $categories = $this->getJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories",
            ['includeItems' => 'true']
        );

        foreach ((array) $categories as $category) {
            foreach ((array) data_get($category, 'items', []) as $item) {
                if (strcasecmp(trim((string) data_get($item, 'name')), $itemName) === 0) {
                    return [
                        'item_id' => (string) (data_get($item, 'id') ?: data_get($item, 'itemId')),
                        'reused_category' => true,
                    ];
                }
            }
        }

        return [];
    }

    private function extractUuidFromConflict(?array $error): ?string
    {
        $message = (string) data_get($error, 'error.message', data_get($error, 'message', ''));

        if (preg_match('/\[([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]/i', $message, $matches)) {
            return $matches[1];
        }

        $conflicts = data_get($error, 'error.conflictingResources', data_get($error, 'conflictingResources'));

        if (is_array($conflicts) && filled($conflicts[0] ?? null)) {
            return (string) $conflicts[0];
        }

        return null;
    }

    /** IDs fixos válidos — reexecutar o seed atualiza em vez de duplicar. */
    private function resolveStableIds(string $merchantId): array
    {
        unset($merchantId);

        return [
            'category' => 'f1a1a1a1-1111-4111-8111-111111111101',
            'item' => 'f1a1a1a1-1111-4111-8111-111111111102',
            'product' => 'f1a1a1a1-1111-4111-8111-111111111103',
            'group' => 'f1a1a1a1-1111-4111-8111-111111111104',
            'option' => 'f1a1a1a1-1111-4111-8111-111111111105',
            'fries_product' => 'f1a1a1a1-1111-4111-8111-111111111106',
        ];
    }

    private function resolveDefaultCatalogId(string $token, string $merchantId): string
    {
        $catalogs = $this->getJson($token, "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs");

        if (! is_array($catalogs) || empty($catalogs)) {
            throw new RuntimeException('Nenhum catálogo encontrado na loja de teste.');
        }

        foreach ($catalogs as $catalog) {
            $contexts = (array) data_get($catalog, 'context', []);

            if (in_array('DEFAULT', $contexts, true)) {
                $id = data_get($catalog, 'catalogId') ?: data_get($catalog, 'id');

                if (filled($id)) {
                    return (string) $id;
                }
            }
        }

        $fallback = data_get($catalogs[0], 'catalogId') ?: data_get($catalogs[0], 'id');

        if (blank($fallback)) {
            throw new RuntimeException('Catálogo iFood sem identificador válido.');
        }

        return (string) $fallback;
    }

    private function getJson(string $token, string $url, array $query = []): mixed
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get($url, $query);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao consultar catálogo iFood: ' . $response->body());
        }

        return $response->json();
    }

    private function putJson(string $token, string $url, array $payload): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->put($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao criar produto no iFood: ' . $response->body());
        }
    }

    private function catalogBaseUrl(): string
    {
        return rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/')
            . '/catalog/v2.0';
    }
}
