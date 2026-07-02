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
        private readonly IfoodService $ifood,
        private readonly IfoodImageService $images,
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
        $isRepublication = filled($product->ifood_item_id) && filled($product->catalog_external_id);

        if ($isRepublication) {
            $this->syncPublishedProduct($store, $product, $token, $merchantId);

            if ($this->needsStructuralItemUpdate($product)) {
                $this->updateItemWithRecovery($token, $merchantId, $store, $product, $category);
            }

            return $product->fresh(['category', 'optionGroups.optionItems']);
        }

        $payload = $this->createItemWithRecovery($token, $merchantId, $store, $product, $category);

        $product->update([
            'ifood_item_id' => $payload['item']['id'],
            'catalog_external_id' => $payload['item']['productId'],
        ]);

        return $product->fresh(['category', 'optionGroups.optionItems']);
    }

    public function syncProductStatus(Product $product): Product
    {
        $product->load(['category', 'optionGroups.optionItems']);
        $store = $this->storeForProduct($product);
        $this->assertStoreReady($store);

        if (blank($product->ifood_item_id)) {
            throw new RuntimeException('Publique o produto no iFood antes de sincronizar o status.');
        }

        $token = $this->ifood->accessTokenForStore($store);
        $merchantId = (string) $store->ifood_merchant_id;

        $this->patchItemStatus(
            $token,
            $merchantId,
            (string) $product->ifood_item_id,
            (bool) $product->is_active
        );

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

        $store = $this->storeForProduct($product);
        $this->assertStoreReady($store);

        if (filled($item->ifood_option_item_id) && filled($product->ifood_item_id)) {
            $token = $this->ifood->accessTokenForStore($store);
            $merchantId = (string) $store->ifood_merchant_id;

            $this->patchOptionStatus(
                $token,
                $merchantId,
                (string) $item->ifood_option_item_id,
                $available
            );

            return $item->fresh();
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
        $itemId = $this->ensureItemId($product);
        $mainProductId = $this->ensureProductId($product);

        $mainImage = $this->prepareIfoodImage(
            $store,
            $product->getRawOriginal('image'),
            'foto do produto "'.$product->name.'"'
        );

        $products = [];
        $optionGroupsPayload = [];
        $optionsPayload = [];
        $mainGroupRefs = [];

        foreach ($product->optionGroups as $groupIndex => $group) {
            $groupId = $this->ensureUuid($group, 'ifood_option_group_id', 'catalog_external_id');
            $optionIds = [];

            foreach ($group->optionItems as $optionIndex => $option) {
                $optionId = $this->ensureOptionItemId($option);
                $optionProductId = $this->optionProductId($option);
                $optionImage = $this->prepareIfoodImage(
                    $store,
                    $option->getRawOriginal('image_url'),
                    'foto do complemento "'.$option->name.'"'
                );

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
        $imagePath = trim((string) ($image['imagePath'] ?? ''));

        if ($imagePath === '') {
            throw new RuntimeException(
                'Não foi possível enviar a imagem para o iFood. Republicar após corrigir a foto do produto.'
            );
        }

        $node = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'imagePath' => $imagePath,
        ];

        if ($optionGroups !== null) {
            $node['optionGroups'] = $optionGroups;
        }

        return $node;
    }

    /**
     * @return array{imagePath: string}
     */
    private function prepareIfoodImage(Store $store, ?string $storedPath, string $label): array
    {
        $dataUri = ImageService::toDataUri($storedPath);

        if ($dataUri === null) {
            throw new RuntimeException(
                'Não foi possível ler a imagem para enviar ao iFood. '
                .ImageService::readFailureHint($storedPath, $label)
            );
        }

        return [
            'imagePath' => $this->uploadImageDataUri($store, $dataUri),
        ];
    }

    private function createItemWithRecovery(
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

            $product->update([
                'ifood_item_id' => $payload['item']['id'],
                'catalog_external_id' => $payload['item']['productId'],
            ]);

            try {
                $this->createItem($token, $merchantId, $payload);

                $imagePath = trim((string) ($payload['products'][0]['imagePath'] ?? ''));

                if ($imagePath === '' || $this->images->isCdnAccessible($imagePath)) {
                    return $payload;
                }

                throw new RuntimeException(
                    'A imagem foi enviada ao iFood, mas ainda não está disponível no CDN. '
                    .'Aguarde 1 minuto e republicar.'
                );
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

    private function updateItemWithRecovery(
        string $token,
        string $merchantId,
        Store $store,
        Product $product,
        ProductCategory $category
    ): void {
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

            for ($imageAttempt = 0; $imageAttempt < 2; $imageAttempt++) {
                if ($imageAttempt > 0) {
                    $product->update(['catalog_external_id' => null]);
                    $product->loadMissing(['optionGroups.optionItems']);

                    foreach ($product->optionGroups as $group) {
                        foreach ($group->optionItems as $option) {
                            $option->update(['catalog_external_id' => null]);
                        }
                    }

                    $product = $product->fresh(['category', 'optionGroups.optionItems']);
                }

                $payload = $this->buildItemPayload($store, $product, (string) $category->ifood_category_id);

                $product->update([
                    'ifood_item_id' => $payload['item']['id'],
                    'catalog_external_id' => $payload['item']['productId'],
                ]);

                try {
                    $this->patchItem($token, $merchantId, $payload);

                    $imagePath = trim((string) ($payload['products'][0]['imagePath'] ?? ''));

                    if ($imagePath === '' || $this->images->isCdnAccessible($imagePath)) {
                        return;
                    }

                    if ($imageAttempt === 1) {
                        throw new RuntimeException(
                            'A imagem foi enviada ao iFood, mas ainda não está disponível no CDN. '
                            .'Aguarde 1 minuto e republicar.'
                        );
                    }
                } catch (RuntimeException $e) {
                    if ($this->isDeletedIfoodResourceConflict($e, 'Category')) {
                        $this->resetIfoodCategoryIds($category);
                        $this->resetIfoodCatalogIds($product);

                        continue 2;
                    }

                    if ($this->isDeletedIfoodResourceConflict($e, 'Item')) {
                        $this->resetIfoodCatalogIds($product);

                        continue 2;
                    }

                    throw $e;
                }
            }
        }

        throw new RuntimeException(
            'Não foi possível atualizar o item no iFood após recuperar IDs excluídos no portal.'
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

    private function ensureItemId(Product $product): string
    {
        if (filled($product->ifood_item_id)) {
            return (string) $product->ifood_item_id;
        }

        return Str::uuid()->toString();
    }

    private function ensureProductId(Product $product): string
    {
        if (filled($product->catalog_external_id)) {
            return (string) $product->catalog_external_id;
        }

        return Str::uuid()->toString();
    }

    private function ensureOptionItemId(OptionItem $option): string
    {
        if (filled($option->ifood_option_item_id)) {
            return (string) $option->ifood_option_item_id;
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
        $timeout = max(60, (int) config('services.ifood.timeout', 20));
        $base = "{$this->catalogBaseUrl()}/merchants/{$merchantId}/image/upload";
        $payload = ['image' => $dataUri];
        $lastError = '';

        foreach ([$base.'/', $base] as $url) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->post($url, $payload);

            if ($response->failed()) {
                $lastError = $response->body();

                continue;
            }

            $imagePath = $this->images->extractUploadPath($response->json() ?? $response->body());

            if ($imagePath !== null) {
                if (! $this->images->isCdnAccessible($imagePath)) {
                    $lastError = 'Upload aceito, mas a imagem ainda não está no CDN do iFood.';

                    continue;
                }

                return $imagePath;
            }

            $lastError = 'Resposta sem imagePath: '.$response->body();
        }

        throw new RuntimeException(
            'Erro ao enviar imagem para o iFood: '.($lastError ?: 'falha desconhecida no upload.')
        );
    }

    private function createItem(string $token, string $merchantId, array $payload): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->put("{$this->catalogBaseUrl()}/merchants/{$merchantId}/items", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao criar item no iFood: '.$response->body());
        }
    }

    private function patchItem(string $token, string $merchantId, array $payload): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/items", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar item no iFood: '.$response->body());
        }
    }

    private function syncPublishedProduct(
        Store $store,
        Product $product,
        string $token,
        string $merchantId
    ): void {
        if (blank($product->ifood_item_id)) {
            throw new RuntimeException('Publique o produto no iFood antes de sincronizar alterações.');
        }

        $this->patchItemPrice(
            $token,
            $merchantId,
            (string) $product->ifood_item_id,
            (float) $product->price
        );

        $this->patchItemStatus(
            $token,
            $merchantId,
            (string) $product->ifood_item_id,
            (bool) $product->is_active
        );

        if (filled($product->catalog_external_id)) {
            $mainImage = $this->prepareIfoodImage(
                $store,
                $product->getRawOriginal('image'),
                'foto do produto "'.$product->name.'"'
            );

            $this->patchCatalogProduct(
                $token,
                $merchantId,
                (string) $product->catalog_external_id,
                $product->name,
                filled($product->description) ? $product->description : $product->name,
                $mainImage
            );
        }

        foreach ($product->optionGroups as $group) {
            if (filled($group->ifood_option_group_id)) {
                $this->patchOptionGroupName(
                    $token,
                    $merchantId,
                    (string) $group->ifood_option_group_id,
                    $group->name
                );
            }

            foreach ($group->optionItems as $option) {
                if (blank($option->ifood_option_item_id)) {
                    continue;
                }

                $this->patchOptionPrice(
                    $token,
                    $merchantId,
                    (string) $option->ifood_option_item_id,
                    (float) $option->price
                );

                $this->patchOptionStatus(
                    $token,
                    $merchantId,
                    (string) $option->ifood_option_item_id,
                    (bool) $option->is_available
                );

                if (blank($option->catalog_external_id)) {
                    continue;
                }

                $optionImage = $this->prepareIfoodImage(
                    $store,
                    $option->getRawOriginal('image_url'),
                    'foto do complemento "'.$option->name.'"'
                );

                $this->patchCatalogProduct(
                    $token,
                    $merchantId,
                    (string) $option->catalog_external_id,
                    $option->name,
                    $option->name,
                    $optionImage
                );
            }
        }
    }

    /**
     * @param  array{imagePath: string}  $image
     */
    private function patchCatalogProduct(
        string $token,
        string $merchantId,
        string $productId,
        string $name,
        string $description,
        array $image
    ): void {
        $imagePath = trim((string) ($image['imagePath'] ?? ''));

        if ($imagePath === '') {
            throw new RuntimeException(
                'Não foi possível enviar a imagem para o iFood. Republicar após corrigir a foto.'
            );
        }

        $payload = [
            'name' => $name,
            'description' => $description,
            'imagePath' => $imagePath,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/products/{$productId}", $payload);

        if ($response->successful()) {
            return;
        }

        if (in_array($response->status(), [404, 405], true)) {
            $this->putCatalogProduct($token, $merchantId, $productId, $name, $description, $imagePath);

            return;
        }

        throw new RuntimeException('Erro ao atualizar produto no iFood: '.$response->body());
    }

    private function putCatalogProduct(
        string $token,
        string $merchantId,
        string $productId,
        string $name,
        string $description,
        string $imagePath
    ): void {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->put("{$this->catalogBaseUrl()}/merchants/{$merchantId}/products/{$productId}", [
                'name' => $name,
                'description' => $description,
                'additionalInformation' => '',
                'serving' => 'NOT_APPLICABLE',
                'dietaryRestrictions' => [],
                'imagePath' => $imagePath,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar produto no iFood: '.$response->body());
        }
    }

    private function patchOptionGroupName(
        string $token,
        string $merchantId,
        string $optionGroupId,
        string $name
    ): void {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/optionGroups/{$optionGroupId}", [
                'name' => $name,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar grupo de complementos no iFood: '.$response->body());
        }
    }

    private function patchItemPrice(string $token, string $merchantId, string $itemId, float $price): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/items/price", [
                'itemId' => $itemId,
                'price' => ['value' => $price],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar preço do item no iFood: '.$response->body());
        }
    }

    private function patchItemStatus(string $token, string $merchantId, string $itemId, bool $available): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/items/status", [
                'itemId' => $itemId,
                'status' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar status do item no iFood: '.$response->body());
        }
    }

    private function patchOptionPrice(string $token, string $merchantId, string $optionId, float $price): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/options/price", [
                'optionId' => $optionId,
                'price' => ['value' => $price],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar preço do complemento no iFood: '.$response->body());
        }
    }

    private function patchOptionStatus(string $token, string $merchantId, string $optionId, bool $available): void
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->patch("{$this->catalogBaseUrl()}/merchants/{$merchantId}/options/status", [
                'optionId' => $optionId,
                'status' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Erro ao atualizar status do complemento no iFood: '.$response->body());
        }
    }

    private function needsStructuralItemUpdate(Product $product): bool
    {
        foreach ($product->optionGroups as $group) {
            if (blank($group->ifood_option_group_id)) {
                return true;
            }

            foreach ($group->optionItems as $option) {
                if (blank($option->ifood_option_item_id)) {
                    return true;
                }
            }
        }

        return false;
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
