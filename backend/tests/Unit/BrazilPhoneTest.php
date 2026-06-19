<?php

namespace Tests\Unit;

use App\Support\BrazilPhone;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrazilPhoneTest extends TestCase
{
    #[Test]
    public function it_normalizes_numbers_with_country_code(): void
    {
        $this->assertSame('5585999999999', BrazilPhone::normalize('(85) 99999-9999'));
        $this->assertSame('5585999999999', BrazilPhone::normalize('5585999999999'));
    }

    #[Test]
    public function it_matches_numbers_with_and_without_country_code(): void
    {
        $this->assertTrue(BrazilPhone::matches('5585999999999', '85999999999'));
        $this->assertTrue(BrazilPhone::matches('(85) 99999-9999', '5585999999999'));
        $this->assertFalse(BrazilPhone::matches('5585888888888', '85999999999'));
    }
}
