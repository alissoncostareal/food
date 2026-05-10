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
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'position' => $request->position ?? 0
            ]);
            DB::commit();
            return response()->json($category, 201);
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
}
