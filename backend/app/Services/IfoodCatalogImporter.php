<?php

namespace App\Services;

use App\Models\OptionGroup;
use App\Models\OptionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class IfoodCatalogImporter
{
    public function __construct(
        private readonly IfoodService $ifood,
        private readonly IfoodImageService $images
    ) {}

    public function import(Store $store): array
    {
        if (! $store->isIfoodConnected()) {
            throw new RuntimeException('Conecte e valide sua loja iFood antes de importar o catálogo.');
        }

        $store->load('plan');

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;
        $catalogId = $this->resolveDefaultCatalogId($token, $merchantId);
        $categories = $this->fetchCategories($token, $merchantId, $catalogId);

        if (empty($categories)) {
            throw new RuntimeException(
                'O catálogo iFood está vazio. Crie produtos no Gestor de Pedidos Web (sandbox) '
                . 'ou use o botão "Criar produtos de teste no iFood" e importe novamente.'
            );
        }

        $stats = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_skipped' => 0,
            'option_groups_synced' => 0,
            'option_items_synced' => 0,
            'product_images_imported' => 0,
            'option_images_imported' => 0,
        ];

        $productsById = $this->buildProductsIndex($token, $merchantId, $categories);

        DB::transaction(function () use ($store, $categories, $productsById, &$stats) {
            foreach ($categories as $index => $categoryPayload) {
                if (! $this->isAvailable($categoryPayload)) {
                    continue;
                }

                $category = $this->upsertCategory($store, $categoryPayload, $index, $stats);

                foreach ((array) data_get($categoryPayload, 'items', []) as $itemPayload) {
                    if ($store->productsLimitReached() && ! $this->productExists($store, $itemPayload)) {
                        $stats['products_skipped']++;

                        continue;
                    }

                    $this->upsertProduct($store, $category, $itemPayload, $productsById, $stats);
                }
            }
        });

        return $stats;
    }

    private function resolveDefaultCatalogId(string $token, string $merchantId): string
    {
        $catalogs = $this->getJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs"
        );

        if (! is_array($catalogs) || empty($catalogs)) {
            throw new RuntimeException('Nenhum catálogo encontrado no iFood para esta loja.');
        }

        foreach ($catalogs as $catalog) {
            $contexts = (array) data_get($catalog, 'context', []);

            if (in_array('DEFAULT', $contexts, true) || data_get($catalog, 'catalogContext') === 'DEFAULT') {
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

    private function fetchCategories(string $token, string $merchantId, string $catalogId): array
    {
        $categories = $this->getJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories",
            ['includeItems' => 'true']
        );

        if (! is_array($categories)) {
            throw new RuntimeException('Resposta inválida ao listar categorias do iFood.');
        }

        return $categories;
    }

    private function upsertCategory(Store $store, array $payload, int $index, array &$stats): ProductCategory
    {
        $ifoodId = (string) (data_get($payload, 'id') ?: data_get($payload, 'categoryId'));
        $name = trim((string) data_get($payload, 'name', 'Sem categoria'));

        $category = ProductCategory::query()
            ->where('store_id', $store->id)
            ->where('ifood_category_id', $ifoodId)
            ->first();

        $data = [
            'name' => $name,
            'position' => (int) (data_get($payload, 'sequence') ?? data_get($payload, 'index') ?? $index),
        ];

        if ($category) {
            $category->update($data);
            $stats['categories_updated']++;
        } else {
            $category = ProductCategory::create([
                ...$data,
                'store_id' => $store->id,
                'ifood_category_id' => $ifoodId,
                'slug' => $this->uniqueCategorySlug($store, $name),
            ]);
            $stats['categories_created']++;
        }

        return $category;
    }

    private function buildProductsIndex(string $token, string $merchantId, array $categories): array
    {
        $productsById = [];

        foreach ($categories as $categoryPayload) {
            $categoryId = (string) (data_get($categoryPayload, 'id') ?: data_get($categoryPayload, 'categoryId'));

            if ($categoryId === '') {
                continue;
            }

            $detail = $this->fetchCategoryItems($token, $merchantId, $categoryId);

            foreach ((array) data_get($detail, 'products', []) as $productPayload) {
                if (! is_array($productPayload)) {
                    continue;
                }

                $productId = (string) data_get($productPayload, 'id');

                if ($productId !== '') {
                    $productsById[$productId] = $productPayload;
                }
            }
        }

        return $productsById;
    }

    private function fetchCategoryItems(string $token, string $merchantId, string $categoryId): array
    {
        $payload = $this->getJson(
            $token,
            "{$this->catalogBaseUrl()}/merchants/{$merchantId}/categories/{$categoryId}/items"
        );

        return is_array($payload) ? $payload : [];
    }

    private function upsertProduct(
        Store $store,
        ProductCategory $category,
        array $payload,
        array $productsById,
        array &$stats
    ): void {
        $ifoodItemId = (string) (data_get($payload, 'id') ?: data_get($payload, 'itemId'));
        $fields = $this->resolveItemFields($payload);

        if ($fields['name'] === '') {
            $stats['products_skipped']++;

            return;
        }

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('ifood_item_id', $ifoodItemId)
            ->first();

        $data = [
            'product_category_id' => $category->id,
            'name' => $fields['name'],
            'description' => $fields['description'],
            'price' => max(0, $fields['price']),
            'is_active' => $fields['is_active'],
            'manage_stock' => false,
            'stock_quantity' => 0,
        ];

        if ($product) {
            $product->update($data);
            $stats['products_updated']++;
        } else {
            if ($store->productsLimitReached()) {
                $stats['products_skipped']++;

                return;
            }

            $product = Product::create([
                ...$data,
                'store_id' => $store->id,
                'ifood_item_id' => $ifoodItemId,
                'slug' => $this->uniqueProductSlug($store, $fields['name']),
            ]);
            $stats['products_created']++;
        }

        $this->syncProductImage($product, $payload, $productsById, $stats);
        $this->syncOptionGroups($product, (array) data_get($payload, 'optionGroups', []), $productsById, $stats);
    }

    private function syncProductImage(Product $product, array $payload, array $productsById, array &$stats): void
    {
        $productId = (string) data_get($payload, 'productId');
        $productPayload = $productsById[$productId] ?? (array) data_get($payload, 'product', []);
        $imagePath = $this->images->extractImagePath(array_merge($payload, ['product' => $productPayload]));

        if ($imagePath === null) {
            return;
        }

        if ($this->images->importForProduct($product, $imagePath)) {
            $stats['product_images_imported']++;
        }
    }

    private function syncOptionGroups(Product $product, array $groups, array $productsById, array &$stats): void
    {
        $seenGroupIds = [];

        foreach ($groups as $index => $groupPayload) {
            if (! $this->isAvailable($groupPayload)) {
                continue;
            }

            $ifoodGroupId = (string) (data_get($groupPayload, 'id') ?: data_get($groupPayload, 'optionGroupId'));

            if ($ifoodGroupId === '') {
                continue;
            }

            $seenGroupIds[] = $ifoodGroupId;

            $group = OptionGroup::query()
                ->where('product_id', $product->id)
                ->where('ifood_option_group_id', $ifoodGroupId)
                ->first();

            $groupData = [
                'name' => trim((string) data_get($groupPayload, 'name', 'Complementos')),
                'min_selected' => max(0, (int) data_get($groupPayload, 'min', 0)),
                'max_selected' => max(1, (int) data_get($groupPayload, 'max', 1)),
            ];

            if ($group) {
                $group->update($groupData);
            } else {
                $group = OptionGroup::create([
                    ...$groupData,
                    'product_id' => $product->id,
                    'ifood_option_group_id' => $ifoodGroupId,
                ]);
                $stats['option_groups_synced']++;
            }

            $this->syncOptionItems($group, (array) data_get($groupPayload, 'options', []), $productsById, $stats);
        }

        if (! empty($seenGroupIds)) {
            OptionGroup::query()
                ->where('product_id', $product->id)
                ->whereNotNull('ifood_option_group_id')
                ->whereNotIn('ifood_option_group_id', $seenGroupIds)
                ->delete();
        }
    }

    private function syncOptionItems(OptionGroup $group, array $options, array $productsById, array &$stats): void
    {
        $seenOptionIds = [];

        foreach ($options as $optionPayload) {
            if (! $this->isAvailable($optionPayload)) {
                continue;
            }

            $ifoodOptionId = (string) (data_get($optionPayload, 'id') ?: data_get($optionPayload, 'optionId'));

            if ($ifoodOptionId === '') {
                continue;
            }

            $seenOptionIds[] = $ifoodOptionId;
            $name = trim((string) (data_get($optionPayload, 'name') ?: data_get($optionPayload, 'description', 'Opção')));

            $item = OptionItem::query()
                ->where('option_group_id', $group->id)
                ->where('ifood_option_item_id', $ifoodOptionId)
                ->first();

            $itemData = [
                'name' => $name,
                'price' => max(0, (float) (data_get($optionPayload, 'price.value') ?? data_get($optionPayload, 'price') ?? 0)),
                'is_available' => true,
            ];

            if ($item) {
                $item->update($itemData);
            } else {
                $item = OptionItem::create([
                    ...$itemData,
                    'option_group_id' => $group->id,
                    'ifood_option_item_id' => $ifoodOptionId,
                ]);
                $stats['option_items_synced']++;
            }

            $optionProductId = (string) data_get($optionPayload, 'productId');
            $optionProductPayload = $productsById[$optionProductId] ?? [];
            $imagePath = $this->images->extractImagePath(array_merge($optionPayload, [
                'product' => $optionProductPayload,
            ]));

            if ($imagePath !== null && $this->images->importForOptionItem($item, $imagePath)) {
                $stats['option_images_imported']++;
            }
        }

        if (! empty($seenOptionIds)) {
            OptionItem::query()
                ->where('option_group_id', $group->id)
                ->whereNotNull('ifood_option_item_id')
                ->whereNotIn('ifood_option_item_id', $seenOptionIds)
                ->delete();
        }
    }

    private function resolveItemFields(array $payload): array
    {
        $name = trim((string) data_get($payload, 'name', ''));
        $description = data_get($payload, 'description');

        if ($name === '' && filled(data_get($payload, 'productId'))) {
            $name = trim((string) data_get($payload, 'product.name', ''));
            $description ??= data_get($payload, 'product.description');
        }

        $price = (float) (data_get($payload, 'price.value') ?? data_get($payload, 'price') ?? 0);

        return [
            'name' => $name,
            'description' => filled($description) ? Str::limit((string) $description, 1000) : null,
            'price' => $price,
            'is_active' => $this->isAvailable($payload),
        ];
    }

    private function productExists(Store $store, array $payload): bool
    {
        $ifoodItemId = (string) (data_get($payload, 'id') ?: data_get($payload, 'itemId'));

        return Product::query()
            ->where('store_id', $store->id)
            ->where('ifood_item_id', $ifoodItemId)
            ->exists();
    }

    private function isAvailable(array $payload): bool
    {
        return strtoupper((string) data_get($payload, 'status', 'AVAILABLE')) === 'AVAILABLE';
    }

    private function uniqueCategorySlug(Store $store, string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'categoria';
        $slug = $baseSlug;
        $counter = 1;

        while (
            ProductCategory::where('store_id', $store->id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function uniqueProductSlug(Store $store, string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'produto';
        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::where('store_id', $store->id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function getJson(string $token, string $url, array $query = []): mixed
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get($url, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                'Erro ao consultar catálogo iFood: ' . ($response->json('message') ?: $response->body())
            );
        }

        return $response->json();
    }

    private function catalogBaseUrl(): string
    {
        return rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/')
            . '/catalog/v2.0';
    }
}
