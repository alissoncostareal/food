<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\DeliveryDriver;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryDriverController extends Controller
{
    use ResolvesMerchantStore;

    public function index()
    {
        try {
            $store = $this->merchantStore();

            return response()->json([
                'data' => $store->deliveryDrivers()
                    ->orderBy('name')
                    ->get(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar entregadores.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $store = $this->merchantStore();

            $driver = DB::transaction(function () use ($store, $validated, $request) {
                return DeliveryDriver::create([
                    'store_id' => $store->id,
                    'name' => trim($validated['name']),
                    'phone' => filled($validated['phone'] ?? null) ? trim($validated['phone']) : null,
                    'is_active' => $request->boolean('is_active', true),
                ]);
            });

            return response()->json($driver, 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao criar entregador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, DeliveryDriver $deliveryDriver)
    {
        $this->assertDriverBelongsToStore($deliveryDriver);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            DB::transaction(function () use ($deliveryDriver, $validated, $request) {
                $deliveryDriver->update([
                    'name' => array_key_exists('name', $validated) ? trim($validated['name']) : $deliveryDriver->name,
                    'phone' => array_key_exists('phone', $validated)
                        ? (filled($validated['phone']) ? trim($validated['phone']) : null)
                        : $deliveryDriver->phone,
                    'is_active' => $request->has('is_active')
                        ? $request->boolean('is_active')
                        : $deliveryDriver->is_active,
                ]);
            });

            return response()->json($deliveryDriver->fresh());
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao atualizar entregador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(DeliveryDriver $deliveryDriver)
    {
        $this->assertDriverBelongsToStore($deliveryDriver);

        try {
            DB::transaction(function () use ($deliveryDriver) {
                $deliveryDriver->orders()->update(['delivery_driver_id' => null]);
                $deliveryDriver->delete();
            });

            return response()->json([
                'message' => 'Entregador removido.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao remover entregador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggle(DeliveryDriver $deliveryDriver)
    {
        $this->assertDriverBelongsToStore($deliveryDriver);

        $deliveryDriver->update([
            'is_active' => ! $deliveryDriver->is_active,
        ]);

        return response()->json($deliveryDriver->fresh());
    }

    private function assertDriverBelongsToStore(DeliveryDriver $deliveryDriver): void
    {
        $store = $this->merchantStore();

        if ((int) $deliveryDriver->store_id !== (int) $store->id) {
            abort(403, 'Entregador não pertence à sua loja.');
        }
    }
}
