<?php

namespace Tests\Unit;

use App\Support\StoreSlug;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreSlugTest extends TestCase
{
    #[Test]
    public function it_strips_instagram_tracking_from_slug(): void
    {
        $this->assertSame('lojademo', StoreSlug::normalize('lojademo?igsh=MzRlODBiNWFlZA=='));
        $this->assertSame('minha-loja', StoreSlug::normalize('minha-loja&fbclid=abc'));
        $this->assertSame('pizzaria', StoreSlug::normalize('PIZZARIA/'));
    }
}
