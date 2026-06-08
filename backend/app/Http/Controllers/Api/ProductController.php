<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index()
    {
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $products = Product::where('store_id', $store->id)
                ->with(['store', 'category', 'optionGroups.optionItems'])
                ->latest()
                ->get();

            return ProductResource::collection($products);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar produtos',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function indexByStore(Store $store)
    {
        try {
            $products = $store->products()
                ->where('is_active', true)
                ->with([
                    'category',
                    'optionGroups.optionItems',
                ])
                ->latest()
                ->get();

            return ProductResource::collection($products);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar produtos da loja',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Usuário não possui uma loja vinculada.',
                ], 403);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'price' => ['required', 'numeric', 'min:0'],
                'product_category_id' => ['required', 'exists:product_categories,id'],
                'description' => ['nullable', 'string'],
                'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            ]);

            DB::beginTransaction();

            $categoryExists = ProductCategory::where('id', $validated['product_category_id'])
                ->where('store_id', $store->id)
                ->exists();

            if (!$categoryExists) {
                DB::rollBack();

                return response()->json([
                    'error' => 'Categoria de produto inválida para sua loja.',
                ], 403);
            }

            $data = $validated;
            unset($data['image']);

            $data['store_id'] = $store->id;
            $data['slug'] = $this->generateUniqueSlug($validated['name']);

            if ($request->hasFile('image')) {
                $data['image'] = ImageService::upload($request->file('image'), 'products');
            }

            $product = Product::create($data);

            DB::commit();

            return new ProductResource($product->fresh(['category', 'optionGroups.optionItems']));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Erro ao criar produto',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(string $id)
    {
        try {
            $product = Product::with(['category', 'optionGroups.optionItems'])->findOrFail($id);

            return new ProductResource($product);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Produto não encontrado',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->firstOrFail();

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'price' => ['sometimes', 'numeric', 'min:0'],
                'description' => ['nullable', 'string'],
                'product_category_id' => ['sometimes', 'exists:product_categories,id'],
                'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            ]);

            if (isset($validated['product_category_id'])) {
                $categoryBelongsToStore = ProductCategory::where('id', $validated['product_category_id'])
                    ->where('store_id', $store->id)
                    ->exists();

                if (!$categoryBelongsToStore) {
                    return response()->json([
                        'error' => 'Categoria de produto inválida para sua loja.',
                    ], 403);
                }
            }

            $data = $validated;
            unset($data['image']);

            if ($request->hasFile('image')) {
                if (!empty($product->image)) {
                    try {
                        ImageService::delete($product->image);
                    } catch (\Exception $imageException) {
                    }
                }

                $data['image'] = ImageService::upload($request->file('image'), 'products');
            }

            if ($request->filled('name')) {
                $data['slug'] = $this->generateUniqueSlug($request->name, $product->id);
            }

            $product->update($data);

            return new ProductResource($product->fresh(['category', 'optionGroups.optionItems']));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar produto',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function destroy(string $id)
    {
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->firstOrFail();

            $hasOrders = DB::table('order_items')
                ->where('product_id', $product->id)
                ->exists();

            if ($hasOrders) {
                $product->forceFill([
                    'is_active' => false,
                ])->save();

                return response()->json([
                    'message' => 'Produto possui pedidos vinculados e foi marcado como indisponível.',
                    'deleted' => false,
                    'is_active' => false,
                ]);
            }

            DB::transaction(function () use ($product) {
                foreach ($product->optionGroups as $group) {
                    $group->optionItems()->delete();
                    $group->delete();
                }

                if (!empty($product->image)) {
                    try {
                        ImageService::delete($product->image);
                    } catch (\Exception $imageException) {
                    }
                }

                $product->delete();
            });

            return response()->json([
                'message' => 'Produto removido com sucesso',
                'deleted' => true,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao remover produto',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->firstOrFail();

            $product->update([
                'is_active' => !$product->is_active,
            ]);

            $status = $product->is_active ? 'disponível' : 'esgotado';

            return response()->json([
                'message' => "O item agora está {$status}!",
                'is_active' => $product->is_active,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar status do produto',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function generateUniqueSlug(string $name, ?int $ignoreProductId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            Product::where('slug', $slug)
            ->when($ignoreProductId, fn($query) => $query->where('id', '!=', $ignoreProductId))
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
