<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\StreetAddress;

class GeocodingService
{
    public function searchAddresses(
        string $query,
        ?string $near = null,
        int $limit = 5,
        ?float $proximityLat = null,
        ?float $proximityLng = null
    ): array {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            return [];
        }

        $cacheKey = 'geocode:search:' . md5(mb_strtolower(implode('|', [
            $query,
            $near ?? '',
            (string) $proximityLat,
            (string) $proximityLng,
            $this->activeGeocodingProvider(),
        ])));

        return Cache::remember($cacheKey, now()->addDay(), function () use ($query, $near, $limit, $proximityLat, $proximityLng) {
            if ($this->geoapifyApiKey() !== '') {
                $geoapifyResults = $this->searchWithGeoapify($query, $near, $proximityLat, $proximityLng, $limit * 2);

                if ($geoapifyResults !== []) {
                    return $this->finalizeAddressResults($geoapifyResults, $query, $near, $limit);
                }
            }

            if ($this->mapboxToken() !== '') {
                $mapboxResults = $this->searchWithMapbox($query, $near, $proximityLat, $proximityLng, $limit * 2);

                if ($mapboxResults !== []) {
                    return $this->finalizeAddressResults($mapboxResults, $query, $near, $limit);
                }
            }

            $results = [];

            if ($near) {
                $results = $this->searchWithNominatim($query, $near, $limit * 2);
            }

            if ($results === []) {
                try {
                    $params = [
                        'q' => $near ? "{$query}, {$near}, Brasil" : "{$query}, Brasil",
                        'limit' => $limit * 2,
                    ];

                    $response = Http::timeout(12)
                        ->get('https://photon.komoot.io/api/', $params);

                    if ($response->successful()) {
                        $features = (array) data_get($response->json(), 'features', []);

                        if ($features !== []) {
                            $results = collect($features)
                                ->map(fn (array $feature) => $this->normalizePhotonFeature($feature))
                                ->filter()
                                ->values()
                                ->all();
                        }
                    }
                } catch (\Throwable) {
                    // fallback abaixo
                }
            }

            if ($results === []) {
                $results = $this->searchWithNominatim($query, $near, $limit * 2);
            }

            return $this->finalizeAddressResults($results, $query, $near, $limit);
        });
    }

    private function finalizeAddressResults(array $results, string $query, ?string $near, int $limit): array
    {
        return collect($results)
            ->filter()
            ->when($near, fn ($collection) => $this->sortResultsByNear($collection, $near))
            ->map(fn (array $item) => $this->enrichResultWithQueryNumber($item, $query))
            ->unique(fn (array $item) => mb_strtolower(($item['address'] ?? '') . '|' . ($item['district'] ?? '') . '|' . ($item['city'] ?? '')))
            ->take($limit)
            ->values()
            ->all();
    }

    private function mapboxToken(): string
    {
        return trim((string) config('services.geocoding.mapbox_token'));
    }

    private function geoapifyApiKey(): string
    {
        return trim((string) config('services.geocoding.geoapify_api_key'));
    }

    private function activeGeocodingProvider(): string
    {
        if ($this->geoapifyApiKey() !== '') {
            return 'geoapify';
        }

        if ($this->mapboxToken() !== '') {
            return 'mapbox';
        }

        return 'osm';
    }

    private function searchWithGeoapify(
        string $query,
        ?string $near,
        ?float $proximityLat,
        ?float $proximityLng,
        int $limit
    ): array {
        $apiKey = $this->geoapifyApiKey();

        if ($apiKey === '') {
            return [];
        }

        try {
            $params = [
                'apiKey' => $apiKey,
                'text' => $query,
                'lang' => 'pt',
                'format' => 'geojson',
                'filter' => 'countrycode:br',
            ];

            if ($proximityLat !== null && $proximityLng !== null) {
                $params['bias'] = "proximity:{$proximityLng},{$proximityLat}";
            } elseif ($near) {
                $point = $this->geocode($near);

                if ($point) {
                    $params['bias'] = "proximity:{$point['lng']},{$point['lat']}";
                }
            }

            $response = Http::timeout(12)
                ->get('https://api.geoapify.com/v1/geocode/autocomplete', $params);

            if (! $response->successful()) {
                return [];
            }

            return collect((array) data_get($response->json(), 'features', []))
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $this->normalizeGeoapifyFeature($item))
                ->filter()
                ->take($limit)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeGeoapifyFeature(array $feature): ?array
    {
        $properties = (array) ($feature['properties'] ?? []);
        $coordinates = (array) data_get($feature, 'geometry.coordinates', []);

        $lat = data_get($properties, 'lat');
        $lng = data_get($properties, 'lon');

        if (($lat === null || $lng === null) && count($coordinates) >= 2) {
            [$lng, $lat] = $coordinates;
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        $resultType = strtolower((string) ($properties['result_type'] ?? ''));

        if (in_array($resultType, ['country', 'state', 'city', 'county', 'postcode'], true)) {
            return null;
        }

        $street = trim((string) ($properties['street'] ?? ''));
        $number = trim((string) ($properties['housenumber'] ?? ''));

        if ($street === '' && ! empty($properties['address_line1'])) {
            $parsed = StreetAddress::split((string) $properties['address_line1']);
            $street = $parsed['street'];
            $number = $number ?: $parsed['number'];
        }

        if ($street === '') {
            $street = trim((string) ($properties['name'] ?? ''));
        }

        if ($street === '') {
            return null;
        }

        $district = trim((string) (
            $properties['suburb']
            ?? $properties['district']
            ?? $properties['neighbourhood']
            ?? ''
        ));

        $city = trim((string) ($properties['city'] ?? $properties['county'] ?? ''));
        $state = trim((string) ($properties['state'] ?? ''));
        $fullStreet = StreetAddress::merge($street, $number);
        $label = trim((string) ($properties['formatted'] ?? ''));

        if ($label === '') {
            $label = implode(', ', array_filter([$fullStreet, $district, $city, $state]));
        }

        return [
            'id' => (string) ($properties['place_id'] ?? $feature['id'] ?? md5($label)),
            'label' => $label,
            'address' => $fullStreet,
            'address_number' => $number,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    private function searchWithMapbox(
        string $query,
        ?string $near,
        ?float $proximityLat,
        ?float $proximityLng,
        int $limit
    ): array {
        $token = $this->mapboxToken();

        if ($token === '') {
            return [];
        }

        try {
            $params = [
                'access_token' => $token,
                'country' => 'br',
                'language' => 'pt',
                'limit' => $limit,
                'types' => 'address,street',
                'autocomplete' => 'true',
            ];

            if ($proximityLat !== null && $proximityLng !== null) {
                $params['proximity'] = "{$proximityLng},{$proximityLat}";
            } elseif ($near) {
                $point = $this->geocode($near);

                if ($point) {
                    $params['proximity'] = "{$point['lng']},{$point['lat']}";
                }
            }

            $searchText = rawurlencode($query);

            $response = Http::timeout(12)
                ->get("https://api.mapbox.com/geocoding/v5/mapbox.places/{$searchText}.json", $params);

            if (! $response->successful()) {
                return [];
            }

            return collect((array) data_get($response->json(), 'features', []))
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $this->normalizeMapboxFeature($item))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeMapboxFeature(array $feature): ?array
    {
        $center = (array) ($feature['center'] ?? []);

        if (count($center) < 2) {
            return null;
        }

        [$lng, $lat] = $center;

        $street = trim((string) ($feature['text'] ?? ''));
        $number = trim((string) ($feature['address'] ?? ''));
        $district = '';
        $city = '';
        $state = '';

        foreach ((array) ($feature['context'] ?? []) as $context) {
            if (! is_array($context)) {
                continue;
            }

            $id = (string) ($context['id'] ?? '');
            $text = trim((string) ($context['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if (str_starts_with($id, 'neighborhood.') || str_starts_with($id, 'locality.')) {
                $district = $district ?: $text;
            }

            if (str_starts_with($id, 'place.')) {
                $city = $text;
            }

            if (str_starts_with($id, 'region.')) {
                $state = $text;
            }
        }

        if ($street === '' && $number === '') {
            return null;
        }

        $fullStreet = StreetAddress::merge($street, $number);
        $label = trim((string) ($feature['place_name'] ?? ''));

        if ($label === '') {
            $label = implode(', ', array_filter([$fullStreet, $district, $city, $state]));
        }

        return [
            'id' => (string) ($feature['id'] ?? md5($label)),
            'label' => $label,
            'address' => $fullStreet,
            'address_number' => $number,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    public function searchDistricts(string $query, ?string $near = null, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'geocode:districts:' . md5(mb_strtolower($query . '|' . ($near ?? '')));

        return Cache::remember($cacheKey, now()->addDay(), function () use ($query, $near, $limit) {
            $results = [];

            try {
                $response = Http::timeout(12)->get('https://photon.komoot.io/api/', [
                    'q' => $near ? "{$query}, {$near}, Brasil" : "{$query}, Brasil",
                    'limit' => $limit * 3,
                ]);

                if ($response->successful()) {
                    $results = collect((array) data_get($response->json(), 'features', []))
                        ->map(fn (array $feature) => $this->normalizePhotonDistrictFeature($feature))
                        ->filter()
                        ->values()
                        ->all();
                }
            } catch (\Throwable) {
                // fallback abaixo
            }

            if ($results === []) {
                $results = collect($this->searchDistrictsWithNominatim($query, $near, $limit))
                    ->values()
                    ->all();
            }

            if ($results === []) {
                $results = collect($this->searchAddresses($query, $near, $limit * 2))
                    ->map(function (array $item) {
                        $districtName = trim((string) ($item['district'] ?? ''));

                        if ($districtName === '') {
                            return null;
                        }

                        return [
                            'id' => $item['id'],
                            'district_name' => $districtName,
                            'label' => implode(', ', array_filter([$districtName, $item['city'] ?? null, $item['state'] ?? null])),
                            'city' => $item['city'] ?? '',
                            'state' => $item['state'] ?? '',
                            'latitude' => $item['latitude'],
                            'longitude' => $item['longitude'],
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            }

            return collect($results)
                ->unique(fn (array $item) => mb_strtolower($item['district_name'] . '|' . ($item['city'] ?? '')))
                ->take($limit)
                ->values()
                ->all();
        });
    }

    public function geocode(string $query): ?array
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $cacheKey = 'geocode:point:' . md5(mb_strtolower($query));

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($query) {
            try {
                $hit = $this->searchWithNominatim("{$query}, Brasil", null, 1)[0] ?? null;

                if ($hit) {
                    return [
                        'lat' => $hit['latitude'],
                        'lng' => $hit['longitude'],
                        'label' => $hit['label'],
                    ];
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        });
    }

    public function geocodeDistrict(string $districtName, ?string $storeAddress = null, ?string $city = null): ?array
    {
        $districtName = trim($districtName);
        $city = trim((string) $city);

        if ($districtName === '') {
            return null;
        }

        $queries = array_values(array_filter([
            $city !== '' ? "{$districtName}, {$city}, Brasil" : null,
            $storeAddress ? "{$districtName}, {$storeAddress}" : null,
            "{$districtName}, Brasil",
        ]));

        foreach ($queries as $query) {
            $point = $this->geocode($query);

            if ($point) {
                return $point;
            }
        }

        return null;
    }

    public function lookupCep(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        $cacheKey = 'cep:' . $cep;

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($cep) {
            try {
                $response = Http::timeout(10)
                    ->get("https://viacep.com.br/ws/{$cep}/json/");

                if (! $response->successful()) {
                    return null;
                }

                $payload = $response->json();

                if (! is_array($payload) || isset($payload['erro'])) {
                    return null;
                }

                $street = trim((string) ($payload['logradouro'] ?? ''));

                return [
                    'cep' => trim((string) ($payload['cep'] ?? $cep)),
                    'address' => $street,
                    'district' => trim((string) ($payload['bairro'] ?? '')),
                    'city' => trim((string) ($payload['localidade'] ?? '')),
                    'state' => trim((string) ($payload['uf'] ?? '')),
                    'complement' => trim((string) ($payload['complemento'] ?? '')),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $cacheKey = 'geocode:reverse:' . md5("{$latitude},{$longitude}");

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($latitude, $longitude) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders($this->nominatimHeaders())
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'addressdetails' => 1,
                        'accept-language' => 'pt-BR',
                    ]);

                if (! $response->successful()) {
                    return null;
                }

                $payload = $response->json();

                if (! is_array($payload)) {
                    return null;
                }

                return $this->normalizeNominatimAddress($payload);
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function searchDistrictsWithNominatim(string $query, ?string $near, int $limit): array
    {
        try {
            $search = trim($near ? "{$query}, {$near}, Brasil" : "{$query}, Brasil");

            $response = Http::timeout(12)
                ->withHeaders($this->nominatimHeaders())
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $search,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => $limit * 3,
                    'countrycodes' => 'br',
                    'accept-language' => 'pt-BR',
                ]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json())
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $this->normalizeNominatimDistrict($item))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function searchWithNominatim(string $query, ?string $near, int $limit): array
    {
        try {
            $search = trim($near ? "{$query}, {$near}, Brasil" : "{$query}, Brasil");

            $response = Http::timeout(12)
                ->withHeaders($this->nominatimHeaders())
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $search,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => $limit,
                    'countrycodes' => 'br',
                    'accept-language' => 'pt-BR',
                ]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json())
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $this->normalizeNominatimAddress($item))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizePhotonDistrictFeature(array $feature): ?array
    {
        $properties = (array) data_get($feature, 'properties', []);
        $coordinates = (array) data_get($feature, 'geometry.coordinates', []);

        if (count($coordinates) < 2) {
            return null;
        }

        [$lng, $lat] = $coordinates;

        $districtName = trim((string) (
            data_get($properties, 'district')
            ?: data_get($properties, 'suburb')
            ?: data_get($properties, 'locality')
            ?: data_get($properties, 'name')
        ));

        if ($districtName === '') {
            return null;
        }

        $city = trim((string) (data_get($properties, 'city') ?: data_get($properties, 'county')));
        $state = trim((string) data_get($properties, 'state'));

        // Ignora ruas e endereços quando houver cidade no contexto.
        $osmKey = strtolower((string) data_get($properties, 'osm_key', ''));
        $osmValue = strtolower((string) data_get($properties, 'osm_value', ''));

        if ($osmKey === 'highway' || in_array($osmValue, ['house', 'street', 'residential', 'road'], true)) {
            return null;
        }
        $labelParts = array_values(array_filter([$districtName, $city, $state]));

        return [
            'id' => (string) (data_get($properties, 'osm_id') ?: md5(json_encode($properties))),
            'district_name' => $districtName,
            'label' => implode(', ', $labelParts),
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    private function normalizeNominatimDistrict(array $payload): ?array
    {
        $lat = data_get($payload, 'lat');
        $lng = data_get($payload, 'lon');

        if ($lat === null || $lng === null) {
            return null;
        }

        $address = (array) data_get($payload, 'address', []);

        $districtName = trim((string) (
            data_get($address, 'suburb')
            ?: data_get($address, 'neighbourhood')
            ?: data_get($address, 'city_district')
            ?: data_get($address, 'district')
            ?: data_get($payload, 'name')
        ));

        if ($districtName === '') {
            return null;
        }

        $city = trim((string) (
            data_get($address, 'city')
            ?: data_get($address, 'town')
            ?: data_get($address, 'municipality')
            ?: data_get($address, 'village')
        ));
        $state = trim((string) data_get($address, 'state'));
        $labelParts = array_values(array_filter([$districtName, $city, $state]));

        return [
            'id' => (string) (data_get($payload, 'place_id') ?: md5($districtName . $city)),
            'district_name' => $districtName,
            'label' => implode(', ', $labelParts),
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    private function normalizePhotonFeature(array $feature): ?array
    {
        $properties = (array) data_get($feature, 'properties', []);
        $coordinates = (array) data_get($feature, 'geometry.coordinates', []);

        if (count($coordinates) < 2) {
            return null;
        }

        [$lng, $lat] = $coordinates;

        $street = trim((string) (
            data_get($properties, 'street')
            ?: data_get($properties, 'name')
            ?: data_get($properties, 'city')
        ));

        if ($street === '') {
            return null;
        }

        $district = trim((string) (
            data_get($properties, 'district')
            ?: data_get($properties, 'suburb')
            ?: data_get($properties, 'city')
        ));

        $city = trim((string) (data_get($properties, 'city') ?: data_get($properties, 'county')));
        $state = trim((string) data_get($properties, 'state'));
        $number = trim((string) data_get($properties, 'housenumber'));

        $labelParts = array_values(array_filter([$street, $number ?: null, $district, $city, $state]));

        return [
            'id' => (string) (data_get($properties, 'osm_id') ?: md5(json_encode($properties))),
            'label' => implode(', ', $labelParts),
            'address' => $street,
            'address_number' => $number,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    private function normalizeNominatimAddress(array $payload): ?array
    {
        $lat = data_get($payload, 'lat');
        $lng = data_get($payload, 'lon');

        if ($lat === null || $lng === null) {
            return null;
        }

        $address = (array) data_get($payload, 'address', []);

        $street = trim((string) (
            data_get($address, 'road')
            ?: data_get($address, 'pedestrian')
            ?: data_get($address, 'residential')
            ?: data_get($payload, 'name')
        ));

        $district = trim((string) (
            data_get($address, 'suburb')
            ?: data_get($address, 'neighbourhood')
            ?: data_get($address, 'city_district')
            ?: data_get($address, 'district')
        ));

        $city = trim((string) (
            data_get($address, 'city')
            ?: data_get($address, 'town')
            ?: data_get($address, 'municipality')
            ?: data_get($address, 'village')
        ));

        $state = trim((string) data_get($address, 'state'));
        $number = trim((string) data_get($address, 'house_number'));
        $label = trim((string) (data_get($payload, 'display_name') ?: implode(', ', array_filter([$street, $number, $district, $city, $state]))));

        if ($label === '') {
            return null;
        }

        return [
            'id' => (string) (data_get($payload, 'place_id') ?: md5($label)),
            'label' => $label,
            'address' => $street ?: $label,
            'address_number' => $number,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    private function enrichResultWithQueryNumber(array $item, string $query): array
    {
        $parsed = StreetAddress::split(trim($query));
        $queryNumber = $parsed['number'];
        $queryStreet = $parsed['street'];

        if ($queryNumber && empty($item['address_number'])) {
            $item['address_number'] = $queryNumber;
        }

        if ($queryNumber) {
            $street = trim((string) ($item['address'] ?? ''));

            if ($street === '' && $queryStreet !== '') {
                $street = $queryStreet;
            }

            $item['address'] = StreetAddress::merge($street, $queryNumber);
        }

        $labelParts = array_values(array_filter([
            $item['address'] ?? null,
            $item['district'] ?? null,
            $item['city'] ?? null,
            $item['state'] ?? null,
        ]));

        if ($labelParts !== []) {
            $item['label'] = implode(', ', $labelParts);
        }

        return $item;
    }

    private function sortResultsByNear($collection, string $near)
    {
        $nearTokens = $this->locationTokens($near);

        if ($nearTokens === []) {
            return $collection;
        }

        return $collection
            ->sortByDesc(function (array $item) use ($nearTokens) {
                $haystack = implode(' ', $this->locationTokens(implode(' ', array_filter([
                    $item['city'] ?? '',
                    $item['district'] ?? '',
                    $item['state'] ?? '',
                    $item['label'] ?? '',
                ]))));

                $score = 0;

                foreach ($nearTokens as $token) {
                    if ($token !== '' && str_contains($haystack, $token)) {
                        $score += 10;
                    }
                }

                if (! empty($item['address_number'])) {
                    $score += 2;
                }

                return $score;
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function locationTokens(?string $value): array
    {
        $normalized = $this->normalizeLocation($value);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,;-]+/', $normalized) ?: []));
    }

    private function normalizeLocation(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? '';
    }

    private function nominatimHeaders(): array
    {
        return [
            'User-Agent' => trim((string) config('services.geocoding.user_agent')),
            'Accept' => 'application/json',
            'Accept-Language' => 'pt-BR',
        ];
    }
}
