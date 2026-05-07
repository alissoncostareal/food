<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $store = Store::all();
            return StoreResource::collection($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar lojas', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $store = Store::create($request->all());
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao criar loja', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $store = Store::findOrFail($id);
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Loja não encontrada', 'details' => $e->getMessage()], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $store = Store::findOrFail($id);
            $store->update($request->all());
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar loja', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $store = Store::findOrFail($id);
            $store->delete();
            return response()->json(['message' => 'Loja removida com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao remover loja', 'details' => $e->getMessage()], 400);
        }
    }
}
