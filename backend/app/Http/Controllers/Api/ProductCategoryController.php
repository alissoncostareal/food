<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    // Lista as categorias da loja do usuário logado
    public function index()
    {
        try {
            $store = Auth::user()->store;
            return response()->json($store->productCategories);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar categorias', 'details' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $store = Auth::user()->store;

            $request->validate([
                'name' => 'required|string|max:255',
                'position' => 'nullable|integer'
            ]);

            $category = ProductCategory::create([
                'store_id' => $store->id,
                'name'     => $request->name,
                'slug'     => Str::slug($request->name),
                'position' => $request->position ?? 0
            ]);
            DB::commit();
            return response()->json($category->fresh(), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao criar categoria', 'details' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $store = Auth::user()->store;
            $category = ProductCategory::where('store_id', $store->id)->findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255',
                'position' => 'nullable|integer'
            ]);

            $category->update([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'position' => $request->position ?? $category->position
            ]);
            DB::commit();
            return response()->json($category);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao atualizar categoria', 'details' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            $category = ProductCategory::where('store_id', Auth::user()->store->id)
                ->findOrFail($id);

            $category->delete();
            DB::commit();

            return response()->json(['message' => 'Categoria removida com sucesso.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao remover categoria', 'details' => $e->getMessage()], 400);
        }
    }
public function reorder(Request $request)
{
    try {
        $store = Auth::user()->store;

        // 1. Validação simplificada para não deixar o Laravel quebrar sozinho
        $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|integer',
            'categories.*.position' => 'required|integer'
        ]);

        DB::beginTransaction();

        foreach ($request->categories as $catData) {
            // 2. Buscamos a categoria pura pelo ID enviado pelo front
            $category = ProductCategory::find($catData['id']);

            // Se não achar o ID de jeito nenhum no banco
            if (!$category) {
                throw new \Exception("O ID {$catData['id']} enviado pelo front-end não existe na tabela product_categories.");
            }

            // Se o ID existe, mas pertence a outra loja (store_id diferente)
            if ($category->store_id !== $store->id) {
                throw new \Exception("A categoria '{$category->name}' (ID {$catData['id']}) pertence à loja ID {$category->store_id}, mas você está logado na loja ID {$store->id}.");
            }

            // Se passou nos testes, atualiza a posição
            $category->update(['position' => $catData['position']]);
        }

        DB::commit();
        return response()->json(['message' => 'Ordem do cardápio atualizada com sucesso!']);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'error' => 'Erro ao reordenar',
            'details' => $e->getMessage() // <-- Isso vai te dar o diagnóstico perfeito no console
        ], 400);
    }
}
}
