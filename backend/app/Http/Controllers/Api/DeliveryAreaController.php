<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\DeliveryArea;
use App\Services\GeocodingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryAreaController extends Controller
{
    use ResolvesMerchantStore;

    public function __construct(
        private readonly GeocodingService $geocoding
    ) {}

    public function index()
    {
        try {
            $store = $this->merchantStore();

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

    public function mapPreview()
    {
        try {
            $store = $this->merchantStore()->load('deliveryAreas');

            $storePoint = $this->resolveStorePoint($store);

            $areas = $store->deliveryAreas
                ->sortBy('district_name')
                ->values()
                ->map(function (DeliveryArea $area) use ($store) {
                    $point = $this->resolveAreaPoint($area, $store->address);

                    return [
                        'id' => $area->id,
                        'district_name' => $area->district_name,
                        'city' => $area->city,
                        'fee' => $area->fee,
                        'estimated_time' => $area->estimated_time,
                        'is_active' => $area->is_active,
                        'latitude' => $point['lat'] ?? null,
                        'longitude' => $point['lng'] ?? null,
                        'label' => $point['label'] ?? null,
                    ];
                });

            return response()->json([
                'store' => [
                    'name' => $store->name,
                    'address' => $store->address,
                    'latitude' => $storePoint['lat'] ?? null,
                    'longitude' => $storePoint['lng'] ?? null,
                ],
                'areas' => $areas,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erro ao montar mapa de entrega.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        try {
            $merchantStore = $this->merchantStore();

            $area = DB::transaction(function () use ($merchantStore, $validated) {
                $area = $merchantStore->deliveryAreas()->create($validated);
                $this->syncAreaCoordinates($area, $merchantStore->address, $validated);

                return $area->fresh();
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
            $merchantStore = $this->merchantStore();

            if ((int) $deliveryArea->store_id !== (int) $merchantStore->id) {
                return response()->json([
                    'message' => 'Área de entrega não encontrada.',
                ], 404);
            }

            DB::transaction(function () use ($deliveryArea, $validated, $merchantStore) {
                $districtChanged = ($validated['district_name'] ?? '') !== $deliveryArea->district_name;
                $cityChanged = ($validated['city'] ?? null) !== $deliveryArea->city;
                $coordsProvided = array_key_exists('latitude', $validated) || array_key_exists('longitude', $validated);
                $deliveryArea->update($validated);

                if ($districtChanged || $cityChanged || $coordsProvided || blank($deliveryArea->latitude) || blank($deliveryArea->longitude)) {
                    $this->syncAreaCoordinates($deliveryArea, $merchantStore->address, $validated);
                }
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
            $store = $this->merchantStore();

            if ((int) $deliveryArea->store_id !== (int) $store->id) {
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
            $store = $this->merchantStore();

            if ((int) $deliveryArea->store_id !== (int) $store->id) {
                return response()->json([
                    'message' => 'Área de entrega não encontrada.',
                ], 404);
            }

            DB::transaction(function () use ($deliveryArea) {
                $deliveryArea->update([
                    'is_active' => ! $deliveryArea->is_active,
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

    private function resolveStorePoint($store): ?array
    {
        if (filled($store->latitude) && filled($store->longitude)) {
            return [
                'lat' => (float) $store->latitude,
                'lng' => (float) $store->longitude,
                'label' => $store->address,
            ];
        }

        if (blank($store->address)) {
            return null;
        }

        $point = $this->geocoding->geocode($store->address);

        if ($point) {
            $store->update([
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
            ]);
        }

        return $point;
    }

    private function resolveAreaPoint(DeliveryArea $area, ?string $storeAddress): ?array
    {
        if (filled($area->latitude) && filled($area->longitude)) {
            return [
                'lat' => (float) $area->latitude,
                'lng' => (float) $area->longitude,
                'label' => trim(implode(', ', array_filter([$area->district_name, $area->city]))),
            ];
        }

        return $this->syncAreaCoordinates($area, $storeAddress);
    }

    private function syncAreaCoordinates(DeliveryArea $area, ?string $storeAddress, array $payload = []): ?array
    {
        if (filled($payload['latitude'] ?? null) && filled($payload['longitude'] ?? null)) {
            $area->update([
                'latitude' => (float) $payload['latitude'],
                'longitude' => (float) $payload['longitude'],
            ]);

            return [
                'lat' => (float) $payload['latitude'],
                'lng' => (float) $payload['longitude'],
                'label' => $area->district_name,
            ];
        }

        $point = $this->geocoding->geocodeDistrict($area->district_name, $storeAddress, $area->city);

        if ($point) {
            $area->update([
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
            ]);
        }

        return $point;
    }

    private function rules(): array
    {
        return [
            'district_name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'fee' => ['required', 'numeric', 'min:0'],
            'estimated_time' => ['required', 'integer', 'min:1', 'max:240'],
            'is_active' => ['required', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
