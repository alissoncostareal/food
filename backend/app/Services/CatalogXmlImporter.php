<?php

namespace App\Services;

use App\Models\OptionGroup;
use App\Models\OptionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

class CatalogXmlImporter
{
    private const CATEGORY_TAGS = ['category', 'categoria', 'cat'];

    private const PRODUCT_TAGS = ['product', 'produto', 'item'];

    private const OPTION_GROUP_TAGS = ['option-group', 'option_group', 'grupo', 'grupo-opcao', 'grupo_opcao', 'complemento'];

    private const OPTION_GROUP_CONTAINER_TAGS = [
        'option-groups',
        'option_groups',
        'grupos',
        'grupos-opcao',
        'grupos_opcao',
        'grupo-opcoes',
        'complementos',
    ];

    private const OPTION_TAGS = ['option', 'opcao', 'option-item', 'option_item', 'item-opcao', 'item_opcao'];

    private const OPTION_ITEM_EXTRA_TAGS = ['item', 'complemento', 'adicional', 'complemento-item', 'complemento_item'];

    private const OPTION_CONTAINER_TAGS = [
        'options',
        'opcoes',
        'items',
        'itens',
        'option-items',
        'option_items',
        'complementos',
    ];

    public function preview(Store $store, string $xmlContent): array
    {
        $parsed = $this->parse($xmlContent);

        return $this->buildPreview($store, $parsed);
    }

    public function import(Store $store, string $xmlContent, bool $updateExisting = true): array
    {
        $store->load('plan');
        $parsed = $this->parse($xmlContent);

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

        DB::transaction(function () use ($store, $parsed, $updateExisting, &$stats) {
            foreach ($parsed['categories'] as $categoryData) {
                $category = $this->upsertCategory($store, $categoryData, $updateExisting, $stats);

                foreach ($categoryData['products'] as $productData) {
                    if ($store->productsLimitReached() && ! $this->productExists($store, $productData)) {
                        $stats['products_skipped']++;

                        continue;
                    }

                    $this->upsertProduct($store, $category, $productData, $updateExisting, $stats);
                }
            }
        });

        return $stats;
    }

    public function parse(string $xmlContent): array
    {
        $root = $this->loadXml($xmlContent);
        $categories = [];

        foreach ($this->findCategoryNodes($root) as $categoryNode) {
            $categoryData = $this->parseCategoryNode($categoryNode);
            $categories[] = $categoryData;
        }

        foreach ($this->findRootProductNodes($root) as $productNode) {
            $productData = $this->parseProductNode($productNode);
            $categoryRef = $productData['category_ref'] ?? 'sem-categoria';

            if (! isset($categories[$categoryRef])) {
                $categories[$categoryRef] = [
                    'external_id' => Str::slug($categoryRef) ?: 'sem-categoria',
                    'name' => $productData['category_name'] ?? $this->humanizeRef($categoryRef),
                    'position' => count($categories),
                    'products' => [],
                ];
            }

            $categories[$categoryRef]['products'][] = $productData;
        }

        if ($categories === []) {
            throw new RuntimeException('Nenhuma categoria ou produto encontrado no XML.');
        }

        return [
            'version' => $this->attr($root, ['version', 'versao']) ?: '1',
            'categories' => array_values($categories),
        ];
    }

    private function buildPreview(Store $store, array $parsed): array
    {
        $categories = count($parsed['categories']);
        $products = 0;
        $optionGroups = 0;
        $options = 0;
        $withImages = 0;
        $newProducts = 0;
        $existingProducts = 0;

        foreach ($parsed['categories'] as $categoryData) {
            foreach ($categoryData['products'] as $productData) {
                $products++;

                if ($this->hasImage($productData)) {
                    $withImages++;
                }

                if ($this->productExists($store, $productData)) {
                    $existingProducts++;
                } else {
                    $newProducts++;
                }

                foreach ($productData['option_groups'] as $groupData) {
                    $optionGroups++;
                    $options += count($groupData['options']);

                    foreach ($groupData['options'] as $optionData) {
                        if ($this->hasImage($optionData)) {
                            $withImages++;
                        }
                    }
                }
            }
        }

        $limit = $store->maxProductsAllowed();
        $remaining = $limit === null
            ? PHP_INT_MAX
            : max(0, $limit - $store->products()->count());

        return [
            'version' => $parsed['version'],
            'categories' => $categories,
            'products' => $products,
            'option_groups' => $optionGroups,
            'options' => $options,
            'with_images' => $withImages,
            'new_products' => $newProducts,
            'existing_products' => $existingProducts,
            'would_skip_products' => max(0, $newProducts - $remaining),
        ];
    }

    private function loadXml(string $content): SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string(trim($content), SimpleXMLElement::class, LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = array_map(
                static fn (\LibXMLError $error) => trim($error->message),
                libxml_get_errors()
            );

            throw new RuntimeException('XML inválido: ' . implode('; ', array_filter($errors)));
        }

        return $xml;
    }

    private function findCategoryNodes(SimpleXMLElement $root): array
    {
        $containers = $this->childrenNamed($root, ['categories', 'categorias', 'category-list', 'category_list']);

        if ($containers !== []) {
            $nodes = [];

            foreach ($containers as $container) {
                foreach ($this->childrenNamed($container, self::CATEGORY_TAGS) as $node) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        }

        return $this->childrenNamed($root, self::CATEGORY_TAGS);
    }

    private function findRootProductNodes(SimpleXMLElement $root): array
    {
        $nodes = [];

        foreach ($this->childrenNamed($root, ['products', 'produtos', 'items', 'itens']) as $container) {
            foreach ($this->childrenNamed($container, self::PRODUCT_TAGS) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function parseCategoryNode(SimpleXMLElement $node): array
    {
        $externalId = $this->resolveExternalId($node, ['id', 'codigo', 'external_id', 'ref']);
        $name = $this->attr($node, ['name', 'nome']) ?: $externalId;

        return [
            'external_id' => $externalId,
            'name' => $name,
            'position' => (int) ($this->attr($node, ['position', 'posicao', 'ordem', 'sequence']) ?? 0),
            'products' => array_map(
                fn (SimpleXMLElement $productNode) => $this->parseProductNode($productNode),
                $this->childrenNamed($node, self::PRODUCT_TAGS)
            ),
        ];
    }

    private function parseProductNode(SimpleXMLElement $node): array
    {
        $externalId = $this->resolveExternalId($node, ['id', 'codigo', 'external_id', 'ref', 'sku']);
        $name = $this->attr($node, ['name', 'nome']) ?: $externalId;

        return [
            'external_id' => $externalId,
            'name' => $name,
            'description' => $this->nullableText($this->attr($node, ['description', 'descricao']) ?: $this->childText($node, ['description', 'descricao'])),
            'price' => $this->parsePrice($this->attr($node, ['price', 'preco', 'valor']) ?: $this->childText($node, ['price', 'preco', 'valor'])),
            'is_active' => $this->parseBool($this->attr($node, ['active', 'ativo', 'disponivel', 'available']), true),
            'category_ref' => $this->attr($node, ['category', 'categoria', 'category_id', 'categoria_id']),
            'category_name' => $this->attr($node, ['category_name', 'categoria_nome', 'nome_categoria']),
            'image' => $this->resolveImage($node),
            'option_groups' => array_map(
                fn (SimpleXMLElement $groupNode) => $this->parseOptionGroupNode($groupNode),
                $this->collectOptionGroupNodes($node)
            ),
        ];
    }

    private function collectOptionGroupNodes(SimpleXMLElement $node): array
    {
        $direct = $this->childrenNamed($node, self::OPTION_GROUP_TAGS);

        if ($direct !== []) {
            return $direct;
        }

        foreach ($this->childrenNamed($node, self::OPTION_GROUP_CONTAINER_TAGS) as $container) {
            $nested = $this->childrenNamed($container, self::OPTION_GROUP_TAGS);

            if ($nested !== []) {
                return $nested;
            }
        }

        return [];
    }

    private function collectOptionItemNodes(SimpleXMLElement $groupNode): array
    {
        $itemTags = array_merge(self::OPTION_TAGS, self::OPTION_ITEM_EXTRA_TAGS);
        $direct = $this->childrenNamed($groupNode, $itemTags);

        if ($direct !== []) {
            return $direct;
        }

        foreach ($this->childrenNamed($groupNode, self::OPTION_CONTAINER_TAGS) as $container) {
            $nested = $this->childrenNamed($container, $itemTags);

            if ($nested !== []) {
                return $nested;
            }
        }

        return [];
    }

    private function parseOptionGroupNode(SimpleXMLElement $node): array
    {
        $externalId = $this->resolveExternalId($node, ['id', 'codigo', 'external_id', 'ref']);
        $name = $this->attr($node, ['name', 'nome']) ?: $externalId;
        $min = (int) ($this->attr($node, ['min', 'minimo', 'min_selected']) ?? 0);
        $max = (int) ($this->attr($node, ['max', 'maximo', 'max_selected']) ?? 1);

        return [
            'external_id' => $externalId,
            'name' => $name,
            'min_selected' => max(0, $min),
            'max_selected' => max(1, $max),
            'options' => array_map(
                fn (SimpleXMLElement $optionNode) => $this->parseOptionNode($optionNode),
                $this->collectOptionItemNodes($node)
            ),
        ];
    }

    private function parseOptionNode(SimpleXMLElement $node): array
    {
        $externalId = $this->resolveExternalId($node, ['id', 'codigo', 'external_id', 'ref']);
        $name = $this->attr($node, ['name', 'nome']) ?: $externalId;

        return [
            'external_id' => $externalId,
            'name' => $name,
            'price' => $this->parsePrice($this->attr($node, ['price', 'preco', 'valor']) ?: $this->childText($node, ['price', 'preco', 'valor'])),
            'is_available' => $this->parseBool($this->attr($node, ['active', 'ativo', 'disponivel', 'available']), true),
            'image' => $this->resolveImage($node),
        ];
    }

    private function upsertCategory(Store $store, array $data, bool $updateExisting, array &$stats): ProductCategory
    {
        $category = ProductCategory::query()
            ->where('store_id', $store->id)
            ->where('catalog_external_id', $data['external_id'])
            ->first();

        if (! $category) {
            $category = ProductCategory::query()
                ->where('store_id', $store->id)
                ->where('slug', Str::slug($data['name']))
                ->first();
        }

        $payload = [
            'name' => $data['name'],
            'position' => $data['position'],
            'catalog_external_id' => $data['external_id'],
        ];

        if ($category) {
            if ($updateExisting) {
                $category->update($payload);
                $stats['categories_updated']++;
            }
        } else {
            $category = ProductCategory::create([
                ...$payload,
                'store_id' => $store->id,
                'slug' => $this->uniqueCategorySlug($store, $data['name']),
            ]);
            $stats['categories_created']++;
        }

        return $category;
    }

    private function upsertProduct(
        Store $store,
        ProductCategory $category,
        array $data,
        bool $updateExisting,
        array &$stats
    ): void {
        if ($data['name'] === '') {
            $stats['products_skipped']++;

            return;
        }

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('catalog_external_id', $data['external_id'])
            ->first();

        if (! $product) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('slug', Str::slug($data['name']))
                ->first();
        }

        $payload = [
            'product_category_id' => $category->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => max(0, $data['price']),
            'is_active' => $data['is_active'],
            'catalog_external_id' => $data['external_id'],
            'manage_stock' => false,
            'stock_quantity' => 0,
        ];

        if ($product) {
            if ($updateExisting) {
                $product->update($payload);
                $stats['products_updated']++;
            }
        } else {
            if ($store->productsLimitReached()) {
                $stats['products_skipped']++;

                return;
            }

            $product = Product::create([
                ...$payload,
                'store_id' => $store->id,
                'slug' => $this->uniqueProductSlug($store, $data['name']),
            ]);
            $stats['products_created']++;
        }

        $this->importProductImage($product, $data, $stats);
        $this->syncOptionGroups($product, $data['option_groups'], $updateExisting, $stats);
    }

    private function syncOptionGroups(Product $product, array $groups, bool $updateExisting, array &$stats): void
    {
        $seenGroupIds = [];

        foreach ($groups as $groupData) {
            if ($groupData['name'] === '') {
                continue;
            }

            $seenGroupIds[] = $groupData['external_id'];

            $group = OptionGroup::query()
                ->where('product_id', $product->id)
                ->where('catalog_external_id', $groupData['external_id'])
                ->first();

            if (! $group) {
                $group = OptionGroup::query()
                    ->where('product_id', $product->id)
                    ->where('name', $groupData['name'])
                    ->first();
            }

            $payload = [
                'name' => $groupData['name'],
                'min_selected' => $groupData['min_selected'],
                'max_selected' => $groupData['max_selected'],
                'catalog_external_id' => $groupData['external_id'],
            ];

            if ($group) {
                if ($updateExisting) {
                    $group->update($payload);
                }
            } else {
                $group = OptionGroup::create([
                    ...$payload,
                    'product_id' => $product->id,
                ]);
                $stats['option_groups_synced']++;
            }

            $this->syncOptionItems($group, $groupData['options'], $updateExisting, $stats);
        }

        if ($updateExisting && ! empty($seenGroupIds)) {
            OptionGroup::query()
                ->where('product_id', $product->id)
                ->whereNotNull('catalog_external_id')
                ->whereNotIn('catalog_external_id', $seenGroupIds)
                ->delete();
        }
    }

    private function syncOptionItems(OptionGroup $group, array $options, bool $updateExisting, array &$stats): void
    {
        $seenOptionIds = [];

        foreach ($options as $optionData) {
            if ($optionData['name'] === '') {
                continue;
            }

            $seenOptionIds[] = $optionData['external_id'];

            $item = OptionItem::query()
                ->where('option_group_id', $group->id)
                ->where('catalog_external_id', $optionData['external_id'])
                ->first();

            if (! $item) {
                $item = OptionItem::query()
                    ->where('option_group_id', $group->id)
                    ->where('name', $optionData['name'])
                    ->first();
            }

            $payload = [
                'name' => $optionData['name'],
                'price' => max(0, $optionData['price']),
                'is_available' => $optionData['is_available'],
                'catalog_external_id' => $optionData['external_id'],
            ];

            if ($item) {
                if ($updateExisting) {
                    $item->update($payload);
                }
            } else {
                $item = OptionItem::create([
                    ...$payload,
                    'option_group_id' => $group->id,
                ]);
                $stats['option_items_synced']++;
            }

            $this->importOptionImage($item, $optionData, $stats);
        }

        if ($updateExisting && ! empty($seenOptionIds)) {
            OptionItem::query()
                ->where('option_group_id', $group->id)
                ->whereNotNull('catalog_external_id')
                ->whereNotIn('catalog_external_id', $seenOptionIds)
                ->delete();
        }
    }

    private function importProductImage(Product $product, array $data, array &$stats): void
    {
        $image = $data['image'] ?? null;

        if ($image === null) {
            return;
        }

        $path = $this->storeImage($image, 'products', $product->image);

        if ($path !== null) {
            $product->update(['image' => $path]);
            $stats['product_images_imported']++;
        }
    }

    private function importOptionImage(OptionItem $item, array $data, array &$stats): void
    {
        $image = $data['image'] ?? null;

        if ($image === null) {
            return;
        }

        $path = $this->storeImage($image, 'options', $item->getRawOriginal('image_url'));

        if ($path !== null) {
            $item->update(['image_url' => $path]);
            $stats['option_images_imported']++;
        }
    }

    private function storeImage(array $image, string $folder, ?string $replacePath = null): ?string
    {
        if ($image['type'] === 'url' && filled($image['value'])) {
            return ImageService::storeFromUrl($image['value'], $folder, $replacePath);
        }

        if ($image['type'] === 'base64' && filled($image['value'])) {
            return ImageService::storeFromBase64($image['value'], $folder, $replacePath);
        }

        return null;
    }

    private function resolveImage(SimpleXMLElement $node): ?array
    {
        $url = $this->attr($node, ['image', 'imagem', 'foto', 'url', 'image_url', 'image-url', 'foto_url', 'foto-url']);
        $base64 = $this->attr($node, ['image_base64', 'imagem_base64', 'foto_base64', 'image-base64']);

        foreach ($this->childrenNamed($node, ['image', 'imagem', 'foto', 'picture', 'photo']) as $imageNode) {
            $encoding = strtolower((string) ($this->attr($imageNode, ['encoding', 'tipo', 'format']) ?? ''));

            if (in_array($encoding, ['base64', 'b64'], true)) {
                $base64 = trim((string) $imageNode);
            } else {
                $childUrl = $this->attr($imageNode, ['url', 'src', 'href', 'link'])
                    ?: $this->childText($imageNode, ['url', 'src', 'href', 'link'])
                    ?: trim((string) $imageNode);

                if (filled($childUrl)) {
                    $url = $childUrl;
                }
            }
        }

        if (filled($base64)) {
            return ['type' => 'base64', 'value' => $base64];
        }

        if (filled($url)) {
            return ['type' => 'url', 'value' => $url];
        }

        return null;
    }

    private function hasImage(array $data): bool
    {
        return isset($data['image']) && filled($data['image']['value'] ?? null);
    }

    private function productExists(Store $store, array $data): bool
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where(function ($query) use ($data) {
                $query->where('catalog_external_id', $data['external_id'])
                    ->orWhere('slug', Str::slug($data['name']));
            })
            ->exists();
    }

    private function resolveExternalId(SimpleXMLElement $node, array $keys): string
    {
        $value = $this->attr($node, $keys);

        if (filled($value)) {
            return Str::limit(trim((string) $value), 120, '');
        }

        $name = $this->attr($node, ['name', 'nome']);

        return Str::limit(Str::slug((string) $name) ?: Str::uuid()->toString(), 120, '');
    }

    private function childrenNamed(SimpleXMLElement $node, array $names): array
    {
        $normalized = array_map(fn (string $name) => $this->normalizeTag($name), $names);
        $results = [];

        foreach ($node->children() as $child) {
            if (in_array($this->localName($child), $normalized, true)) {
                $results[] = $child;
            }
        }

        return $results;
    }

    private function localName(SimpleXMLElement $node): string
    {
        return $this->normalizeTag($node->getName());
    }

    private function normalizeTag(string $name): string
    {
        return str_replace('_', '-', strtolower(trim($name)));
    }

    private function attr(SimpleXMLElement $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            $attributes = $node->attributes();

            if ($attributes === null) {
                continue;
            }

            foreach ($attributes as $attrName => $value) {
                if ($this->normalizeTag((string) $attrName) === $this->normalizeTag($key)) {
                    $text = trim((string) $value);

                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    private function childText(SimpleXMLElement $node, array $tags): ?string
    {
        foreach ($this->childrenNamed($node, $tags) as $child) {
            $text = trim((string) $child);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 1000);
    }

    private function parsePrice(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = str_replace(['R$', ' '], '', (string) $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return max(0, (float) $normalized);
    }

    private function parseBool(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'sim', 'yes', 'ativo', 'available', 'disponivel'], true);
    }

    private function humanizeRef(string $ref): string
    {
        return Str::title(str_replace(['-', '_'], ' ', $ref));
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
}
