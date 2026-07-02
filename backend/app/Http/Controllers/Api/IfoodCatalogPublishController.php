<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\OptionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\IfoodCatalogHomologationService;
use App\Services\IfoodCatalogPublisher;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\JsonResponse;
use Throwable;

class IfoodCatalogPublishController extends Controller
{
    use ResolvesMerchantStore;

    public function homologationStatus(IfoodCatalogHomologationService $homologation): JsonResponse
    {
        $store = $this->merchantStore();

        return response()->json([
            'homologation' => $homologation->build($store),
        ]);
    }

    public function publishCategory(ProductCategory $category, IfoodCatalogPublisher $publisher): JsonResponse
    {
        $store = $this->merchantStore();
        $this->assertCategoryBelongsToStore($category, $store->id);

        try {
            $published = $publisher->publishCategory($category);

            return response()->json([
                'message' => 'Categoria publicada no iFood.',
                'category' => $published,
            ]);
        } catch (Throwable $e) {
            return $this->publishError('publish_category', $e, $store->id);
        }
    }

    public function publishProduct(Product $product, IfoodCatalogPublisher $publisher): JsonResponse
    {
        $store = $this->merchantStore();
        $this->assertProductBelongsToStore($product, $store->id);

        try {
            $wasPublished = filled($product->ifood_item_id);
            $published = $publisher->publishProduct($product);

            return response()->json([
                'message' => ! $wasPublished
                    ? 'Produto publicado no iFood.'
                    : ($this->needsStructuralRepublicationMessage($published)
                        ? 'Produto e novos complementos atualizados no iFood.'
                        : 'Dados sincronizados com o iFood (nome, foto, preço e status).'),
                'product' => new ProductResource($published),
            ]);
        } catch (Throwable $e) {
            return $this->publishError('publish_product', $e, $store->id);
        }
    }

    public function pauseOptionItem(OptionItem $optionItem, IfoodCatalogPublisher $publisher): JsonResponse
    {
        return $this->setOptionItemAvailability($optionItem, $publisher, false);
    }

    public function resumeOptionItem(OptionItem $optionItem, IfoodCatalogPublisher $publisher): JsonResponse
    {
        return $this->setOptionItemAvailability($optionItem, $publisher, true);
    }

    private function setOptionItemAvailability(
        OptionItem $optionItem,
        IfoodCatalogPublisher $publisher,
        bool $available
    ): JsonResponse {
        $store = $this->merchantStore();
        $optionItem->load('optionGroup.product');

        $product = $optionItem->optionGroup?->product;

        if (! $product instanceof Product || (int) $product->store_id !== (int) $store->id) {
            return response()->json([
                'message' => 'Complemento não encontrado.',
            ], 404);
        }

        try {
            $item = $available
                ? $publisher->resumeOptionItem($optionItem)
                : $publisher->pauseOptionItem($optionItem);

            return response()->json([
                'message' => $available
                    ? 'Complemento reativado no iFood.'
                    : 'Complemento pausado no iFood.',
                'option_item' => $item,
                'product' => new ProductResource($product->fresh(['category', 'optionGroups.optionItems'])),
            ]);
        } catch (Throwable $e) {
            return $this->publishError($available ? 'resume_option_item' : 'pause_option_item', $e, $store->id);
        }
    }

    private function assertCategoryBelongsToStore(ProductCategory $category, int $storeId): void
    {
        if ((int) $category->store_id !== $storeId) {
            abort(404, 'Categoria não encontrada.');
        }
    }

    private function assertProductBelongsToStore(Product $product, int $storeId): void
    {
        if ((int) $product->store_id !== $storeId) {
            abort(404, 'Produto não encontrado.');
        }
    }

    private function needsStructuralRepublicationMessage(Product $product): bool
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

    private function publishError(string $action, Throwable $e, int $storeId): JsonResponse
    {
        $reported = IntegrationErrorReporter::report(
            'ifood',
            $action,
            $e,
            ['store_id' => $storeId]
        );

        return response()->json(
            IntegrationErrorReporter::response($e->getMessage(), $reported['error_ref']),
            400
        );
    }
}
