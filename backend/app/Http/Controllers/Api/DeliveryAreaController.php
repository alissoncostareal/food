<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryAreaController extends Controller
{
    public function index()
    {
        try {
            $store = Auth::user()?->store;

            if (!$store) {
                return response()->json([
                    'message' => 'Loja não encontrada.',
                ], 404);
            }

            return response()->json([
                'data' => $store->deliveryAreas()
                    ->orderBy('district_name')
                    ->get(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar áreas de entrega.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        try {
            $store = Auth::user()?->store;

            if (!$store) {
                return response()->json([
                    'message' => 'Loja não encontrada.',
                ], 404);
            }

            $area = DB::transaction(function () use ($store, $validated) {
                return $store->deliveryAreas()->create($validated);
            });

            return response()->json([
                'message' => 'Área de entrega criada.',
                'data' => $area,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao criar área de entrega.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, DeliveryArea $deliveryArea)
    {
        $validated = $request->validate($this->rules());

        try {
            $store = Auth::user()?->store;

            if (!$store || (int) $deliveryArea->store_id !== (int) $store->id) {
                return response()->json([
                    'message' => 'Área de entrega não encontrada.',
                ], 404);
            }

            DB::transaction(function () use ($deliveryArea, $validated) {
                $deliveryArea->update($validated);
            });

            return response()->json([
                'message' => 'Área de entrega atualizada.',
                'data' => $deliveryArea->fresh(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao atualizar área de entrega.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(DeliveryArea $deliveryArea)
    {
        try {
            $store = Auth::user()?->store;

            if (!$store || (int) $deliveryArea->store_id !== (int) $store->id) {
                return response()->json([
                    'message' => 'Área de entrega não encontrada.',
                ], 404);
            }

            DB::transaction(function () use ($deliveryArea) {
                $deliveryArea->delete();
            });

            return response()->json([
                'message' => 'Área de entrega removida.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao remover área de entrega.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggle(DeliveryArea $deliveryArea)
    {
        try {
            $store = Auth::user()?->store;

            if (!$store || (int) $deliveryArea->store_id !== (int) $store->id) {
                return response()->json([
                    'message' => 'Área de entrega não encontrada.',
                ], 404);
            }

            DB::transaction(function () use ($deliveryArea) {
                $deliveryArea->update([
                    'is_active' => !$deliveryArea->is_active,
                ]);
            });

            return response()->json([
                'message' => $deliveryArea->fresh()->is_active ? 'Área ativada.' : 'Área pausada.',
                'data' => $deliveryArea->fresh(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao alterar status da área.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function rules(): array
    {
        return [
            'district_name' => ['required', 'string', 'max:120'],
            'fee' => ['required', 'numeric', 'min:0'],
            'estimated_time' => ['required', 'integer', 'min:1', 'max:240'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
