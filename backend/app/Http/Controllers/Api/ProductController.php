<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Services\ImageService;
use Auth;
use DB;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $products = Product::with(['store', 'category', 'optionGroups.optionItems'])->get();
            return ProductResource::collection($products);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar produtos', 'details' => $e->getMessage()], 400);
        }
    }

    public function indexByStore(Store $store)
    {
        try {
            // 1. Carregamos os produtos da loja já trazendo a categoria e os grupos de opções
            // Isso evita que o Laravel faça uma consulta no banco para cada item da lista (Problema N+1)
            $products = $store->products()
            ->with([
                'category',
                'optionGroups.optionItems' // O ponto indica: carregue grupos E os itens de cada grupo
            ])
            ->latest()
            ->get();

            // 2. Retornamos a collection através do Resource
            // O Resource se encarregará de transformar isso no formato que o Vue espera
            return ProductResource::collection($products);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar produtos da loja', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
                // 1. Pegamos a loja antes da validação
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json(['error' => 'Usuário não possui uma loja vinculada.'], 403);
            }

            // 2. Injetamos o store_id no request para passar na validação e no create
            $request->merge(['store_id' => $store->id]);

            $request->validate([
                'store_id'            => 'required|exists:stores,id',
                'name'                => 'required|string|max:255',
                'price'               => 'required|numeric',
                'product_category_id' => 'required|exists:product_categories,id',
                'description'         => 'nullable|string',
                'image'               => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            ]);

            DB::beginTransaction();

            // 3. Validar se a categoria pertence à loja dele
            $categoryExists = ProductCategory::where('id', $request->product_category_id)
                ->where('store_id', $store->id)
                ->exists();

            if (!$categoryExists) {
                return response()->json(['error' => 'Categoria de produto inválida para sua loja.'], 403);
            }

            $data = $request->except('image'); // Pega tudo, menos a imagem original do request

            if ($request->hasFile('image')) {
                $path = ImageService::upload($request->file('image'), 'products');
                $data['image'] = $path; // Injeta o caminho retornado pelo Service
            }

            $data['slug'] = \Illuminate\Support\Str::slug($request->name);
            $data['store_id'] = $store->id;

            $product = Product::create($data);

            DB::commit();
            return new ProductResource($product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Erro ao criar produto', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            // Carrega o produto com os grupos e os itens desses grupos
            $product = Product::with(['category', 'optionGroups.optionItems'])->findOrFail($id);
            return new ProductResource($product);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Produto não encontrado'], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'price' => 'sometimes|numeric|min:0',
                'description' => 'nullable|string',
            ]);

            $product->update($validated);
            return new ProductResource($product);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar produto'], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $product = Product::findOrFail($id);
            ImageService::delete($product->image);
            $product->delete();
            return response()->json(['message' => 'Produto removido com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao remover produto'], 400);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $product = Product::where('id', $id)
            ->where('store_id', Auth::user()->store->id)
            ->firstOrFail();

            $product->update([
                'is_active' => !$product->is_active
            ]);

            $status = $product->is_active ? 'disponível' : 'esgotado';

            return response()->json([
                'message' => "O item agora está {$status}!",
                'is_active' => $product->is_active
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar status do produto', 'details' => $e->getMessage()], 400);
    }
    }
}
