<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\Request;

class GeocodingController extends Controller
{
    public function search(Request $request, GeocodingService $geocoding)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:180'],
            'near' => ['nullable', 'string', 'max:180'],
            'proximity_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'proximity_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'type' => ['nullable', 'in:address,district'],
        ]);

        $type = $validated['type'] ?? 'address';
        $proximityLat = isset($validated['proximity_lat']) ? (float) $validated['proximity_lat'] : null;
        $proximityLng = isset($validated['proximity_lng']) ? (float) $validated['proximity_lng'] : null;

        $data = $type === 'district'
            ? $geocoding->searchDistricts($validated['q'], $validated['near'] ?? null)
            : $geocoding->searchAddresses(
                $validated['q'],
                $validated['near'] ?? null,
                5,
                $proximityLat,
                $proximityLng
            );

        return response()->json([
            'data' => $data,
        ]);
    }

    public function cep(Request $request, GeocodingService $geocoding)
    {
        $validated = $request->validate([
            'cep' => ['required', 'string', 'min:8', 'max:9'],
        ]);

        $result = $geocoding->lookupCep($validated['cep']);

        if (! $result) {
            return response()->json([
                'message' => 'CEP não encontrado.',
            ], 404);
        }

        return response()->json([
            'data' => $result,
        ]);
    }

    public function reverse(Request $request, GeocodingService $geocoding)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $geocoding->reverseGeocode(
            (float) $validated['latitude'],
            (float) $validated['longitude']
        );

        if (! $result) {
            return response()->json([
                'message' => 'Endereço não encontrado para esta localização.',
            ], 404);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
