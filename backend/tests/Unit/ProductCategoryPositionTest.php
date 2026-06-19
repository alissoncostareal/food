<?php

namespace Tests\Unit;

use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductCategoryPositionTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(): Store
    {
        $user = User::factory()->create();

        return Store::factory()->create([
            'user_id' => $user->id,
        ]);
    }

    private function createCategory(Store $store, string $name, int $position): ProductCategory
    {
        return ProductCategory::create([
            'store_id' => $store->id,
            'name' => $name,
            'slug' => $name,
            'position' => $position,
        ]);
    }

    #[Test]
    public function it_inserts_category_at_beginning_and_shifts_existing_positions(): void
    {
        $store = $this->createStore();
        $first = $this->createCategory($store, 'Bebidas', 0);
        $second = $this->createCategory($store, 'Lanches', 1);

        $position = ProductCategory::resolveInsertPosition($store->id, 0);
        ProductCategory::makeRoomAtPosition($store->id, $position);

        $inserted = ProductCategory::create([
            'store_id' => $store->id,
            'name' => 'Promoções',
            'slug' => 'promocoes',
            'position' => $position,
        ]);

        $this->assertSame(0, $inserted->position);
        $this->assertSame(1, $first->fresh()->position);
        $this->assertSame(2, $second->fresh()->position);
    }

    #[Test]
    public function it_moves_category_down_and_reorders_siblings(): void
    {
        $store = $this->createStore();
        $first = $this->createCategory($store, 'Bebidas', 0);
        $second = $this->createCategory($store, 'Lanches', 1);
        $third = $this->createCategory($store, 'Sobremesas', 2);

        $first->reposition(2);
        $first->save();

        $this->assertSame(2, $first->fresh()->position);
        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $third->fresh()->position);
    }

    #[Test]
    public function it_moves_category_up_and_reorders_siblings(): void
    {
        $store = $this->createStore();
        $first = $this->createCategory($store, 'Bebidas', 0);
        $second = $this->createCategory($store, 'Lanches', 1);
        $third = $this->createCategory($store, 'Sobremesas', 2);

        $third->reposition(0);
        $third->save();

        $this->assertSame(0, $third->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
        $this->assertSame(2, $second->fresh()->position);
    }
}
