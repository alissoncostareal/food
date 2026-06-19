<?php

namespace Tests\Unit;

use App\Models\DeliveryArea;
use App\Support\DeliveryAreaMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeliveryAreaMatcherTest extends TestCase
{
    #[Test]
    public function it_matches_vila_velha_in_fortaleza_by_district_name(): void
    {
        $area = new DeliveryArea([
            'id' => 1,
            'district_name' => 'Vila Velha',
            'city' => 'Fortaleza',
        ]);

        $this->assertTrue(DeliveryAreaMatcher::matches($area, 'Vila Velha', 'Fortaleza'));
    }

    #[Test]
    public function it_matches_when_district_comes_from_full_address_line(): void
    {
        $area = new DeliveryArea([
            'id' => 1,
            'district_name' => 'Vila Velha',
            'city' => 'Fortaleza',
        ]);

        $this->assertTrue(DeliveryAreaMatcher::matches($area, 'Mateus Tavares 18, Vila Velha', 'Fortaleza'));
    }

    #[Test]
    public function it_matches_when_city_is_missing_on_checkout(): void
    {
        $area = new DeliveryArea([
            'id' => 1,
            'district_name' => 'Vila Velha',
            'city' => 'Fortaleza',
        ]);

        $this->assertTrue(DeliveryAreaMatcher::matches($area, 'Vila Velha', null));
    }

    #[Test]
    public function it_finds_area_by_id_first(): void
    {
        $areas = collect([
            tap(new DeliveryArea(['district_name' => 'Outro', 'city' => 'Fortaleza']), fn ($area) => $area->id = 1),
            tap(new DeliveryArea(['district_name' => 'Vila Velha', 'city' => 'Fortaleza']), fn ($area) => $area->id = 2),
        ]);

        $match = DeliveryAreaMatcher::find($areas, 2, 'Bairro Errado', 'Fortaleza');

        $this->assertSame(2, $match?->id);
    }
}
