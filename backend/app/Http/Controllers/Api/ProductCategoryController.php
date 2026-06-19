<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    use ResolvesMerchantStore;

    public function index()
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $categories = $store->productCategories()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            return response()->json($categories);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar categorias',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'position' => ['nullable', 'integer', 'min:0'],
            ]);

            $category = DB::transaction(function () use ($store, $validated) {
                $position = ProductCategory::resolveInsertPosition(
                    $store->id,
                    array_key_exists('position', $validated) ? (int) $validated['position'] : null
                );

                ProductCategory::makeRoomAtPosition($store->id, $position);

                return ProductCategory::create([
                    'store_id' => $store->id,
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name']),
                    'position' => $position,
                ]);
            });

            return response()->json($category->fresh(), 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar categoria',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $category = ProductCategory::where('id', $id)
                ->where('store_id', $store->id)
                ->firstOrFail();

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'position' => ['nullable', 'integer', 'min:0'],
            ]);

            DB::transaction(function () use ($category, $validated) {
                if (array_key_exists('position', $validated) && $validated['position'] !== null) {
                    $category->reposition((int) $validated['position']);
                }

                $category->update([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name']),
                    'position' => $category->position,
                ]);
            });

            return response()->json($category->fresh());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar categoria',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function destroy($id)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $category = ProductCategory::where('id', $id)
                ->where('store_id', $store->id)
                ->withCount('products')
                ->firstOrFail();

            if ($category->products_count > 0) {
                return response()->json([
                    'error' => 'Categoria possui produtos vinculados.',
                    'message' => 'Remova ou mova os produtos dessa categoria antes de excluir.',
                ], 422);
            }

            DB::transaction(function () use ($category) {
                $category->delete();
            });

            return response()->json([
                'message' => 'Categoria removida com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao remover categoria',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function reorder(Request $request)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $validated = $request->validate([
                'categories' => ['required', 'array'],
                'categories.*.id' => ['required', 'integer'],
                'categories.*.position' => ['required', 'integer'],
            ]);

            DB::transaction(function () use ($store, $validated) {
                $categoryIds = collect($validated['categories'])->pluck('id')->all();

                $validCount = ProductCategory::where('store_id', $store->id)
                    ->whereIn('id', $categoryIds)
                    ->count();

                if ($validCount !== count($categoryIds)) {
                    throw new \Exception('Uma ou mais categorias não pertencem à sua loja.');
                }

                foreach ($validated['categories'] as $categoryData) {
                    ProductCategory::where('id', $categoryData['id'])
                        ->where('store_id', $store->id)
                        ->update([
                            'position' => $categoryData['position'],
                        ]);
                }
            });

            return response()->json([
                'message' => 'Ordem do cardápio atualizada com sucesso!',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao reordenar categorias',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
