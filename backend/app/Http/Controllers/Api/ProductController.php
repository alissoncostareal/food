<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
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
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Usuário não possui uma loja vinculada.',
                ], 403);
            }

            $store->load('plan');

            if (!$store->plan) {
                return response()->json([
                    'message' => 'Sua loja ainda não possui um plano vinculado.',
                    'error' => 'Plano não configurado.',
                    'upgrade_required' => true,
                ], 403);
            }

            if (!$store->hasActiveSubscription()) {
                return response()->json([
                    'message' => 'Sua assinatura não está ativa.',
                    'error' => 'Assinatura inativa.',
                    'subscription_status' => $store->subscription_status,
                    'upgrade_required' => true,
                ], 403);
            }

            $maxProducts = $store->maxProductsAllowed();
            $currentProducts = $store->products()->count();

            if (!is_null($maxProducts) && $currentProducts >= $maxProducts) {
                return response()->json([
                    'message' => "Seu plano permite até {$maxProducts} produtos.",
                    'error' => 'Limite de produtos atingido.',
                    'limit' => $maxProducts,
                    'current' => $currentProducts,
                    'upgrade_required' => true,
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
            $data['is_active'] = $request->boolean('is_active', true);

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
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->with(['category', 'optionGroups.optionItems'])
                ->firstOrFail();

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
            $store = $this->merchantStore();

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
                'is_active' => ['nullable', 'boolean'],
                'show_in_cart' => ['nullable', 'boolean'],
                'cart_highlight_order' => ['nullable', 'integer', 'min:0', 'max:999'],
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

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }

            if ($request->has('show_in_cart')) {
                $data['show_in_cart'] = $request->boolean('show_in_cart');
            }

            if ($request->has('cart_highlight_order')) {
                $data['cart_highlight_order'] = $request->input('cart_highlight_order');
            }

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
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->with('optionGroups.optionItems')
                ->firstOrFail();

            $hasOrders = DB::table('order_items')
                ->where('product_id', $product->id)
                ->exists();

            if ($hasOrders) {
                $product->forceFill([
                    'is_active' => false,
                    'show_in_cart' => false,
                    'cart_highlight_order' => null,
                ])->save();

                return response()->json([
                    'message' => 'Produto possui pedidos vinculados e foi marcado como indisponível.',
                    'deleted' => false,
                    'is_active' => false,
                    'show_in_cart' => false,
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

    public function toggleCartHighlight($id)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $product = Product::where('id', $id)
                ->where('store_id', $store->id)
                ->firstOrFail();

            $nextValue = !$product->show_in_cart;

            $highlightCount = Product::where('store_id', $store->id)
                ->where('show_in_cart', true)
                ->where('id', '!=', $product->id)
                ->count();

            if ($nextValue && $highlightCount >= 12) {
                return response()->json([
                    'message' => 'Você pode destacar no máximo 12 produtos no carrinho.',
                    'error' => 'Limite de destaques atingido.',
                ], 422);
            }

            $product->update([
                'show_in_cart' => $nextValue,
                'cart_highlight_order' => $nextValue
                    ? ($product->cart_highlight_order ?? ($highlightCount + 1))
                    : null,
            ]);

            return response()->json([
                'message' => $nextValue
                    ? 'Produto adicionado aos destaques do carrinho.'
                    : 'Produto removido dos destaques do carrinho.',
                'show_in_cart' => $product->show_in_cart,
                'cart_highlight_order' => $product->cart_highlight_order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar destaque do carrinho',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $store = $this->merchantStore();

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
