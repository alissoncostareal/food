<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OptionGroupController extends Controller
{
    use ResolvesMerchantStore;

    public function store(Request $request, Product $product)
    {
        try {
            $this->authorizeProduct($product);

            $validated = $request->validate($this->optionGroupRules());

            return DB::transaction(function () use ($request, $product, $validated) {
                $group = $product->optionGroups()->create([
                    'name' => $validated['name'],
                    'min_selected' => $validated['min_selected'],
                    'max_selected' => $validated['max_selected'],
                ]);

                foreach ($validated['items'] as $index => $itemData) {
                    $this->validateOptionItemImage($request, $index);

                    $item = $group->optionItems()->create([
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'is_available' => $this->toBoolean($itemData['is_available'] ?? true),
                        'image_url' => null,
                    ]);

                    $this->storeOptionItemImage($request, $index, $item);
                }

                return response()->json([
                    'message' => 'Grupo e itens criados com sucesso!',
                    'data' => $group->load('optionItems'),
                ], 201);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao salvar opcionais',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function update(Request $request, Product $product, $group)
    {
        try {
            $this->authorizeProduct($product);

            $validated = $request->validate($this->optionGroupRules(updating: true));

            return DB::transaction(function () use ($request, $product, $group, $validated) {
                $optionGroup = $product->optionGroups()->findOrFail($group);

                $optionGroup->update([
                    'name' => $validated['name'],
                    'min_selected' => $validated['min_selected'],
                    'max_selected' => $validated['max_selected'],
                ]);

                $sentItemIds = collect($validated['items'])
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $itemsToDelete = $optionGroup->optionItems()
                    ->whereNotIn('id', $sentItemIds)
                    ->get();

                foreach ($itemsToDelete as $itemToDelete) {
                    if (! empty($itemToDelete->image_url)) {
                        ImageService::deleteStored($itemToDelete->image_url);
                    }

                    $itemToDelete->delete();
                }

                foreach ($validated['items'] as $index => $itemData) {
                    $this->validateOptionItemImage($request, $index);

                    $itemPayload = [
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'is_available' => $this->toBoolean($itemData['is_available'] ?? true),
                    ];

                    if (! empty($itemData['id'])) {
                        $item = $optionGroup->optionItems()
                            ->where('id', (int) $itemData['id'])
                            ->firstOrFail();

                        $item->update($itemPayload);
                    } else {
                        $item = $optionGroup->optionItems()->create($itemPayload);
                    }

                    $this->storeOptionItemImage($request, $index, $item);
                }

                return response()->json([
                    'message' => 'Grupo de opcionais atualizado com sucesso!',
                    'data' => $optionGroup->fresh('optionItems'),
                ], 200);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar opcionais',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function destroy(Product $product, $group)
    {
        try {
            $this->authorizeProduct($product);

            DB::transaction(function () use ($product, $group) {
                $optionGroup = $product->optionGroups()->findOrFail($group);

                foreach ($optionGroup->optionItems as $item) {
                    if (! empty($item->image_url)) {
                        ImageService::deleteStored($item->image_url);
                    }

                    $item->delete();
                }

                $optionGroup->delete();
            });

            return response()->json([
                'message' => 'Removido com sucesso',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Grupo não encontrado',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    private function optionGroupRules(bool $updating = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'min_selected' => ['required', 'integer', 'min:0'],
            'max_selected' => ['required', 'integer', 'min:1', 'gte:min_selected'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.is_available' => ['nullable'],
        ];

        if ($updating) {
            $rules['items.*.id'] = ['nullable', 'integer'];
        }

        return $rules;
    }

    private function validateOptionItemImage(Request $request, int $index): void
    {
        $key = $this->resolveOptionItemUploadKey($request, $index);

        if ($key === null) {
            return;
        }

        $request->validate(
            [
                $key => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            ],
            [
                "{$key}.mimes" => 'A foto do complemento deve ser JPG, PNG ou WebP.',
                "{$key}.max" => 'A foto do complemento deve ter no máximo 10 MB.',
                "{$key}.required" => 'Selecione uma foto válida para o complemento.',
                "{$key}.file" => 'Não foi possível ler o arquivo de imagem do complemento.',
            ]
        );
    }

    private function resolveOptionItemUploadKey(Request $request, int $index): ?string
    {
        $fileKey = "items.{$index}.image_url";
        $fallbackFileKey = "items.{$index}.image";

        if ($request->hasFile($fileKey) && $request->file($fileKey)->isValid()) {
            return $fileKey;
        }

        if ($request->hasFile($fallbackFileKey) && $request->file($fallbackFileKey)->isValid()) {
            return $fallbackFileKey;
        }

        return null;
    }

    private function storeOptionItemImage(Request $request, int $index, $item): void
    {
        $key = $this->resolveOptionItemUploadKey($request, $index);

        if ($key === null) {
            return;
        }

        $file = $request->file($key);
        $path = ImageService::upload($file, 'products/options');
        $previousPath = $item->getRawOriginal('image_url');

        $item->update([
            'image_url' => $path,
        ]);

        if (filled($previousPath) && $previousPath !== $path) {
            ImageService::deleteStored($previousPath);
        }
    }

    private function authorizeProduct(Product $product): void
    {
        $store = $this->merchantStore();

        if ((int) $product->store_id !== (int) $store->id) {
            throw new RuntimeException('Este produto não pertence à sua loja.');
        }
    }

    private function toBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
