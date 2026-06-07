<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\OptionGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OptionGroupController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'min_selected' => 'required|integer|min:0',
            'max_selected' => 'required|integer|min:1',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.price' => 'required|numeric|min:0',
            // 🛑 Adicionamos a validação para os arquivos de imagem dos itens (opcional)
            'items.*.image_url' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        try {
            return DB::transaction(function () use ($request, $product) {
                $group = $product->optionGroups()->create([
                    'name' => $request->name,
                    'min_selected' => $request->min_selected,
                    'max_selected' => $request->max_selected,
                ]);

                // 🛑 Capturamos o index ($index) para conseguir rastrear o arquivo no Request
                foreach ($request->items as $index => $itemData) {

                    $item = $group->optionItems()->create([
                        'name' => $itemData['name'],
                        'price' => $itemData['price'],
                        'is_available' => true,
                        'image_url' => null // Inicializa como nulo
                    ]);

                    /**
                     * 🛑 O SEGREDO PARA REQUISIÇÕES MULTIPART:
                     * Quando enviado via FormData do Vue, o Laravel enxerga o arquivo binário
                     * na chave exata estruturada por pontos: "items.0.image", "items.1.image", etc.
                     */
                    $fileKey = "items.{$index}.image_url";

                    if ($request->hasFile($fileKey)) {
                        // Faz o upload usando o seu Service padrão do sistema
                        $path = \App\Services\ImageService::upload($request->file($fileKey), 'products/options');

                        // Atualiza o registro com o caminho retornado
                        $item->update(['image_url' => $path]);
                    }
                }

                return response()->json([
                    'message' => 'Grupo e itens criados com sucesso!',
                    'data' => $group->load('optionItems')
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao salvar opcionais', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Atualiza o grupo de opcionais e seus itens sincronizando-os
     */
    public function update(Request $request, Product $product, $groupId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'min_selected' => 'required|integer|min:0',
            'max_selected' => 'required|integer|min:1',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer', // Itens existentes terão ID, novos não
            'items.*.name' => 'required|string|max:255',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.image_url' => 'nullable', // Pode vir String (url antiga) ou File (nova imagem)
        ]);

        try {
            return DB::transaction(function () use ($request, $product, $groupId) {
                // Garante que o grupo pertence a este produto
                $group = $product->optionGroups()->findOrFail($groupId);

                // 1. Atualiza os dados do cabeçalho do grupo
                $group->update([
                    'name' => $request->name,
                    'min_selected' => $request->min_selected,
                    'max_selected' => $request->max_selected,
                ]);

                // Mapeia os IDs dos itens enviados para saber quais manter
                $sentItemIds = collect($request->items)->pluck('id')->filter()->toArray();

                // Deleta do banco os itens antigos que NÃO foram enviados no novo payload
                $group->optionItems()->whereNotIn('id', $sentItemIds)->delete();

                // 2. Processa e sincroniza cada um dos itens enviados
                foreach ($request->items as $index => $itemData) {

                    // Se o item já tiver ID, atualiza. Senão, cria um novo no grupo.
                    $item = $group->optionItems()->updateOrCreate(
                        ['id' => $itemData['id'] ?? null],
                        [
                            'name' => $itemData['name'],
                            'price' => $itemData['price'],
                            'is_available' => $itemData['is_available'] ?? true,
                        ]
                    );

                    // Verifica se o arquivo binário foi enviado para este índice específico
                    $fileKey = "items.{$index}.image_url";
                    if ($request->hasFile($fileKey)) {
                        // Se houver imagem nova, faz o upload e salva o caminho
                        $path = \App\Services\ImageService::upload($request->file($fileKey), 'products/options');
                        $item->update(['image_url' => $path]);
                    }
                }

                return response()->json([
                    'message' => 'Grupo de opcionais atualizado com sucesso!',
                    'data' => $group->load('optionItems')
                ], 200);
            });
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar opcionais', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove o grupo de opcionais
     * Ajustado para receber o ID e validar a posse
     */
    public function destroy(Product $product, $groupId)
    {
        try {
            // Busca o grupo dentro do relacionamento do produto
            $group = $product->optionGroups()->findOrFail($groupId);

            // Itens são deletados automaticamente se houver Cascade no Banco,
            // senão, deletamos manualmente aqui:
            $group->optionItems()->delete();
            $group->delete();

            return response()->json(['message' => 'Removido com sucesso'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Grupo não encontrado'], 404);
        }
    }
}
