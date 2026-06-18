<?php

namespace App\Services;

use App\Models\OptionGroup;
use App\Models\OptionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class IfoodCatalogPublisher
{
    public function __construct(
        private readonly IfoodService $ifood
    ) {}

    public function publishCategory(ProductCategory $category): ProductCategory
    {
        $store = $this->storeForCategory($category);
        $this->assertStoreReady($store);

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;
        $catalogId = $this->resolveDefaultCatalogId($token, $merchantId);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $category = $category->fresh();
            $categoryUuid = $this->ensureUuid($category, 'ifood_category_id', 'catalog_external_id');

            try {
                if (filled($category->ifood_category_id)) {
                    $this->postCategory($token, $merchantId, $catalogId, $category, $categoryUuid);

                    return $category->fresh();
                }

                $this->createCategory($token, $merchantId, $catalogId, $category, $categoryUuid);

                return $category->fresh();
            } catch (RuntimeException $e) {
                if ($attempt === 0 && $this->isDeletedIfoodResourceConflict($e, 'Category')) {
                    $this->resetIfoodCategoryIds($category);

                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('Não foi possível publicar a categoria no iFood após recuperar o ID excluído.');
    }

    public function publishProduct(Product $product): Product
    {
        $product->load(['category', 'optionGroups.optionItems']);
        $store = $this->storeForProduct($product);
        $this->assertStoreReady($store);

        $category = $product->category;

        if (! $category instanceof ProductCategory) {
            throw new RuntimeException('Associe o produto a uma categoria antes de publicar no iFood.');
        }

        if (blank($category->ifood_category_id)) {
            $category = $this->publishCategory($category);
        }

        $this->assertProductReadyForIfood($product);

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;
        $payload = $this->publishItemWithRecovery($token, $merchantId, $store, $product, $category);

        $product->update([
            'ifood_item_id' => $payload['item']['id'],
            'catalog_external_id' => $payload['item']['productId'],
        ]);

        return $product->fresh(['category', 'optionGroups.optionItems']);
    }

    public function pauseOptionItem(OptionItem $item): OptionItem
    {
        return $this->setOptionItemAvailability($item, false);
    }

    public function resumeOptionItem(OptionItem $item): OptionItem
    {
        return $this->setOptionItemAvailability($item, true);
    }

    private function setOptionItemAvailability(OptionItem $item, bool $available): OptionItem
    {
        $item->load('optionGroup.product.category');
        $item->update(['is_available' => $available]);

        $product = $item->optionGroup?->product;

        if (! $product instanceof Product) {
            throw new RuntimeException('Produto do complemento não encontrado.');
        }

        $this->publishProduct($product->fresh(['category', 'optionGroups.optionItems']));

        return $item->fresh();
    }

    private function assertProductReadyForIfood(Product $product): void
    {
        if (blank($product->image)) {
            throw new RuntimeException('O produto precisa de foto antes de publicar no iFood.');
        }

        foreach ($product->optionGroups as $group) {
            foreach ($group->optionItems as $item) {
                if (blank($item->getRawOriginal('image_url'))) {
                    throw new RuntimeException(
                        'O complemento "'.$item->name.'" precisa de foto antes de publicar no iFood.'
                    );
                }
            }
        }
    }

    private function buildItemPayload(Store $store, Product $product, string $categoryIfoodId): array
    {
        $itemId = $this->ensureUuid($product, 'ifood_item_id', 'catalog_external_id');
        $mainProductId = filled($product->catalog_external_id)
            ? (string) $product->catalog_external_id
            : Str::uuid()->toString();

        $mainImage = $this->prepareIfoodImage($store, $product->image);

        $products = [];
        $optionGroupsPayload = [];
        $optionsPayload = [];
        $mainGroupRefs = [];

        foreach ($product->optionGroups as $groupIndex => $group) {
            $groupId = $this->ensureUuid($group, 'ifood_option_group_id', 'catalog_external_id');
            $optionIds = [];

            foreach ($group->optionItems as $optionIndex => $option) {
                $optionId = $this->ensureUuid($option, 'ifood_option_item_id', 'catalog_external_id');
                $optionProductId = $this->optionProductId($option);
                $optionImage = $this->prepareIfoodImage($store, $option->getRawOriginal('image_url'));

                $products[] = $this->buildProductNode(
                    $optionProductId,
                    $option->name,
                    $option->name,
                    $optionImage
                );

                $optionsPayload[] = [
                    'id' => $optionId,
                    'status' => $option->is_available ? 'AVAILABLE' : 'UNAVAILABLE',
                    'index' => $optionIndex,
                    'productId' => $optionProductId,
                    'price' => ['value' => (float) $option->price],
                ];

                $optionIds[] = $optionId;

                $option->update([
                    'ifood_option_item_id' => $optionId,
                    'catalog_external_id' => $optionProductId,
                ]);
            }

            $optionGroupsPayload[] = [
                'id' => $groupId,
                'name' => $group->name,
                'status' => 'AVAILABLE',
                'index' => $groupIndex,
                'optionGroupType' => 'DEFAULT',
                'optionIds' => $optionIds,
            ];

            $mainGroupRefs[] = [
                'id' => $groupId,
                'min' => (int) $group->min_selected,
                'max' => (int) $group->max_selected,
            ];

            $group->update([
                'ifood_option_group_id' => $groupId,
                'catalog_external_id' => $groupId,
            ]);
        }

        array_unshift($products, $this->buildProductNode(
            $mainProductId,
            $product->name,
            filled($product->description) ? $product->description : $product->name,
            $mainImage,
            $mainGroupRefs
        ));

        return [
            'item' => [
                'id' => $itemId,
                'type' => 'DEFAULT',
                'categoryId' => $categoryIfoodId,
                'status' => $product->is_active ? 'AVAILABLE' : 'UNAVAILABLE',
                'price' => ['value' => (float) $product->price],
                'index' => 0,
                'productId' => $mainProductId,
            ],
            'products' => $products,
            'optionGroups' => $optionGroupsPayload,
            'options' => $optionsPayload,
        ];
    }

    private function buildProductNode(
        string $id,
        string $name,
        string $description,
        array $image,
        ?array $optionGroups = null
    ): array {
        $node = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
        ];

        if (filled($image['imagePath'] ?? null)) {
            $node['imagePath'] = (string) $image['imagePath'];
        } else {
            $node['image'] = (string) $image['dataUri'];
        }

        if ($optionGroups !== null) {
            $node['optionGroups'] = $optionGroups;
        }

        return $node;
    }

    /**
     * @return array{dataUri: string, imagePath: ?string}
     */
    private function prepareIfoodImage(Store $store, ?string $storedPath): array
    {
        $dataUri = ImageService::toDataUri($storedPath);

        if ($dataUri === null) {
            throw new RuntimeException(
                'Não foi possível ler a imagem para enviar ao iFood. '
                .'Confira se o arquivo existe no storage e use JPG ou PNG.'
            );
        }

        try {
            return [
                'dataUri' => $dataUri,
                'imagePath' => $this->uploadImageDataUri($store, $dataUri),
            ];
        } catch (RuntimeException) {
            return [
                'dataUri' => $dataUri,
                'imagePath' => null,
            ];
        }
    }

    private function publishItemWithRecovery(
        string $token,
        string $merchantId,
        Store $store,
        Product $product,
        ProductCategory $category
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $product = $product->fresh(['category', 'optionGroups.optionItems']);
            $category = $product->category ?? $category->fresh();

            if (! $category instanceof ProductCategory) {
                throw new RuntimeException('Associe o produto a uma categoria antes de publicar no iFood.');
            }

            if (blank($category->ifood_category_id)) {
                $category = $this->publishCategory($category);
                $product->setRelation('category', $category);
            }

            $payload = $this->buildItemPayload($store, $product, (string) $category->ifood_category_id);

            try {
                $this->putItem($token, $merchantId, $payload);

                return $payload;
            } catch (RuntimeException $e) {
                if ($this->isDeletedIfoodResourceConflict($e, 'Category')) {
                    $this->resetIfoodCategoryIds($category);
                    $this->resetIfoodCatalogIds($product);

                    continue;
                }

                if ($this->isDeletedIfoodResourceConflict($e, 'Item')) {
                    $this->resetIfoodCatalogIds($product);

                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException(
            'Não foi possível publicar o item no iFood após recuperar IDs excluídos no portal.'
        );
    }

    private function createCategory(
        string $token,
        string $merchantId,
        string $catalogId,
        ProductCategory $category,
        string $categoryUuid
    ): void {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post("{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories", [
                'id' => $categoryUuid,
                'name' => $category->name,
                'status' => 'AVAILABLE',
                'template' => 'DEFAULT',
                'sequence' => (int) ($category->position ?? 0),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao publicar categoria no iFood: '.$response->body());
        }

        $category->update([
            'ifood_category_id' => $categoryUuid,
            'catalog_external_id' => $categoryUuid,
        ]);
    }

    private function optionProductId(OptionItem $option): string
    {
        if (filled($option->catalog_external_id)) {
            return (string) $option->catalog_external_id;
        }

        return Str::uuid()->toString();
    }

    private function ensureUuid(Model $model, string $primaryColumn, string $fallbackColumn): string
    {
        $existing = $model->getAttribute($primaryColumn) ?: $model->getAttribute($fallbackColumn);

        if (filled($existing)) {
            return (string) $existing;
        }

        return Str::uuid()->toString();
    }

    private function uploadImageDataUri(Store $store, string $dataUri): string
    {
        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(max(60, (int) config('services.ifood.timeout', 20)))
            ->post("{$this->catalogBaseUrl()}/merchants/{$merchantId}/image/upload/", [
                'image' => $dataUri,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao enviar imagem para o iFood: '.$response->body());
        }

        $body = $response->json();
        $imagePath = data_get($body, 'imagePath')
            ?: data_get($body, 'path')
            ?: data_get($body, 'image.path')
            ?: data_get($body, 'data.imagePath');

        $imagePath = is_string($imagePath) ? trim($imagePath) : '';

        if ($imagePath === '') {
            throw new RuntimeException('O iFood não retornou o caminho da imagem após o upload.');
        }

        return ltrim($imagePath, '/');
    }

    private function putItem(string $token, string $merchantId, array $payload): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->put("{$this->catalogBaseUrl()}/merchants/{$merchantId}/items", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao publicar item no iFood: '.$response->body());
        }
    }

    private function isDeletedIfoodResourceConflict(RuntimeException $exception, string $resource): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '"code":"Conflict"')
            && str_contains($message, 'deleted '.$resource);
    }

    private function resetIfoodCategoryIds(ProductCategory $category): void
    {
        $category->update([
            'ifood_category_id' => null,
            'catalog_external_id' => null,
        ]);
    }

    private function resetIfoodCatalogIds(Product $product): void
    {
        $product->loadMissing(['optionGroups.optionItems']);

        $product->update([
            'ifood_item_id' => null,
            'catalog_external_id' => null,
        ]);

        foreach ($product->optionGroups as $group) {
            $group->update([
                'ifood_option_group_id' => null,
                'catalog_external_id' => null,
            ]);

            foreach ($group->optionItems as $option) {
                $option->update([
                    'ifood_option_item_id' => null,
                    'catalog_external_id' => null,
                ]);
            }
        }
    }

    private function postCategory(
        string $token,
        string $merchantId,
        string $catalogId,
        ProductCategory $category,
        string $categoryUuid
    ): void {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post("{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs/{$catalogId}/categories", [
                'id' => $categoryUuid,
                'name' => $category->name,
                'status' => 'AVAILABLE',
                'template' => 'DEFAULT',
                'sequence' => (int) ($category->position ?? 0),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar categoria no iFood: '.$response->body());
        }
    }

    private function resolveDefaultCatalogId(string $token, string $merchantId): string
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$this->catalogBaseUrl()}/merchants/{$merchantId}/catalogs");

        if ($response->failed()) {
            throw new RuntimeException('Erro ao consultar catálogos iFood: '.$response->body());
        }

        $catalogs = $response->json();

        if (! is_array($catalogs) || $catalogs === []) {
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

    private function assertStoreReady(Store $store): void
    {
        if (! $store->isIfoodConnected()) {
            throw new RuntimeException('Conecte e valide a loja iFood antes de publicar o catálogo.');
        }
    }

    private function storeForCategory(ProductCategory $category): Store
    {
        $store = $category->store;

        if (! $store instanceof Store) {
            $store = Store::query()->find($category->store_id);
        }

        if (! $store instanceof Store) {
            throw new RuntimeException('Loja da categoria não encontrada.');
        }

        return $store;
    }

    private function storeForProduct(Product $product): Store
    {
        $store = $product->store;

        if (! $store instanceof Store) {
            $store = Store::query()->find($product->store_id);
        }

        if (! $store instanceof Store) {
            throw new RuntimeException('Loja do produto não encontrada.');
        }

        return $store;
    }

    private function catalogBaseUrl(): string
    {
        return rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/')
            .'/catalog/v2.0';
    }
}
