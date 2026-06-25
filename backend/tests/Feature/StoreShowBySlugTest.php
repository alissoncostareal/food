<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreShowBySlugTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_finds_store_when_tracking_params_are_embedded_in_slug_segment(): void
    {
        Store::factory()->create([
            'slug' => 'lojademo',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(7),
        ]);

        $this->getJson('/api/v1/stores/lojademo?fbclid=abc')
            ->assertOk()
            ->assertJsonPath('store.slug', 'lojademo');

        $dirtySlug = rawurlencode('lojademo?fbclid=abc');

        $this->getJson("/api/v1/stores/{$dirtySlug}")
            ->assertOk()
            ->assertJsonPath('store.slug', 'lojademo');
    }
}
