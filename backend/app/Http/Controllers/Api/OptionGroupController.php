<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OptionGroupController extends Controller
{
    public function store(Request $request, Product $product)
    {
        try {
            $this->authorizeProduct($product);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'min_selected' => ['required', 'integer', 'min:0'],
                'max_selected' => ['required', 'integer', 'min:1'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.name' => ['required', 'string', 'max:255'],
                'items.*.price' => ['required', 'numeric', 'min:0'],
                'items.*.image_url' => ['nullable'],
                'items.*.image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
                'items.*.is_available' => ['nullable'],
            ]);

            return DB::transaction(function () use ($request, $product, $validated) {
                $group = $product->optionGroups()->create([
                    'name' => $validated['name'],
                    'min_selected' => $validated['min_selected'],
                    'max_selected' => $validated['max_selected'],
                ]);

                foreach ($validated['items'] as $index => $itemData) {
                    $item = $group->optionItems()->create([
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'is_available' => $this->toBoolean($itemData['is_available'] ?? true),
                        'image_url' => null,
                    ]);

                    $fileKey = "items.{$index}.image_url";
                    $fallbackFileKey = "items.{$index}.image";

                    if ($request->hasFile($fileKey) || $request->hasFile($fallbackFileKey)) {
                        $file = $request->file($fileKey) ?: $request->file($fallbackFileKey);
                        $path = ImageService::upload($file, 'products/options');

                        $item->update([
                            'image_url' => $path,
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Grupo e itens criados com sucesso!',
                    'data' => $group->load('optionItems'),
                ], 201);
            });
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

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'min_selected' => ['required', 'integer', 'min:0'],
                'max_selected' => ['required', 'integer', 'min:1'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.id' => ['nullable', 'integer'],
                'items.*.name' => ['required', 'string', 'max:255'],
                'items.*.price' => ['required', 'numeric', 'min:0'],
                'items.*.image_url' => ['nullable'],
                'items.*.image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
                'items.*.is_available' => ['nullable'],
            ]);

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
                    ->values()
                    ->all();

                $itemsToDelete = $optionGroup->optionItems()
                    ->whereNotIn('id', $sentItemIds)
                    ->get();

                foreach ($itemsToDelete as $itemToDelete) {
                    if (!empty($itemToDelete->image_url)) {
                        try {
                            ImageService::delete($itemToDelete->image_url);
                        } catch (\Exception $imageException) {
                        }
                    }

                    $itemToDelete->delete();
                }

                foreach ($validated['items'] as $index => $itemData) {
                    $itemPayload = [
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'is_available' => $this->toBoolean($itemData['is_available'] ?? true),
                    ];

                    if (!empty($itemData['id'])) {
                        $item = $optionGroup->optionItems()
                            ->where('id', $itemData['id'])
                            ->firstOrFail();

                        $item->update($itemPayload);
                    } else {
                        $item = $optionGroup->optionItems()->create($itemPayload);
                    }

                    $fileKey = "items.{$index}.image_url";
                    $fallbackFileKey = "items.{$index}.image";

                    if ($request->hasFile($fileKey) || $request->hasFile($fallbackFileKey)) {
                        if (!empty($item->image_url)) {
                            try {
                                ImageService::delete($item->image_url);
                            } catch (\Exception $imageException) {
                            }
                        }

                        $file = $request->file($fileKey) ?: $request->file($fallbackFileKey);
                        $path = ImageService::upload($file, 'products/options');

                        $item->update([
                            'image_url' => $path,
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Grupo de opcionais atualizado com sucesso!',
                    'data' => $optionGroup->fresh('optionItems'),
                ], 200);
            });
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
                    if (!empty($item->image_url)) {
                        try {
                            ImageService::delete($item->image_url);
                        } catch (\Exception $imageException) {
                        }
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

    private function authorizeProduct(Product $product): void
    {
        $store = Auth::user()?->store;

        if (!$store) {
            throw new \Exception('Loja não encontrada para este usuário.');
        }

        if ((int) $product->store_id !== (int) $store->id) {
            throw new \Exception('Este produto não pertence à sua loja.');
        }
    }

    private function toBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
