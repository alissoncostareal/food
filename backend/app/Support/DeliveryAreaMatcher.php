<?php

namespace App\Support;

use App\Models\DeliveryArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DeliveryAreaMatcher
{
    public static function find(
        Collection $areas,
        ?int $deliveryAreaId,
        ?string $district,
        ?string $city
    ): ?DeliveryArea {
        if ($deliveryAreaId) {
            $byId = $areas->first(
                fn (DeliveryArea $area) => (int) $area->id === $deliveryAreaId
            );

            if ($byId) {
                return $byId;
            }
        }

        foreach (self::districtCandidates($district) as $candidate) {
            $match = $areas->first(
                fn (DeliveryArea $area) => self::matches($area, $candidate, $city)
            );

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    public static function matches(DeliveryArea $area, ?string $district, ?string $city): bool
    {
        if (! self::districtMatches($area, $district)) {
            return false;
        }

        return self::cityMatches($area->city, $city);
    }

    public static function districtMatches(DeliveryArea $area, ?string $district): bool
    {
        $normalizedDistrict = self::normalize($district);

        if ($normalizedDistrict === '') {
            return false;
        }

        if (self::namesMatch($area->district_name, $district)) {
            return true;
        }

        $combinedLabel = trim(implode(', ', array_filter([
            $area->district_name,
            $area->city,
        ])));

        if ($combinedLabel !== '' && self::namesMatch($combinedLabel, $district)) {
            return true;
        }

        return false;
    }

    public static function cityMatches(?string $areaCity, ?string $inputCity): bool
    {
        if (blank($areaCity) || blank($inputCity)) {
            return true;
        }

        return self::namesMatch($areaCity, $inputCity);
    }

    public static function districtCandidates(?string $district): array
    {
        $district = trim((string) $district);

        if ($district === '') {
            return [];
        }

        $candidates = [$district];

        if (str_contains($district, ',')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $district))));

            if ($parts !== []) {
                $candidates[] = $parts[0];
                $candidates[] = $parts[count($parts) - 1];
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    public static function namesMatch(?string $left, ?string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    public static function normalize(?string $value): string
    {
        $normalized = Str::ascii(Str::lower(trim((string) $value)));

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }
}
