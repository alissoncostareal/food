<?php

namespace Tests\Unit;

use App\Services\CatalogXmlImporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogXmlImporterTest extends TestCase
{
    #[Test]
    public function it_parses_option_items_inside_containers_with_images(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<partiumenu-catalog version="1">
  <categories>
    <category id="pizzas" name="Pizzas">
      <product id="pizza-teste" name="Pizza Teste" price="40.00">
        <option-groups>
          <option-group id="tamanho" name="Tamanho" min="1" max="1">
            <options>
              <option id="grande" name="Grande" price="5.00" image="https://example.com/grande.jpg"/>
              <item id="gigante" name="Gigante" price="10.00">
                <imagem url="https://example.com/gigante.jpg"/>
              </item>
            </options>
          </option-group>
        </option-groups>
      </product>
    </category>
  </categories>
</partiumenu-catalog>
XML;

        $parsed = (new CatalogXmlImporter())->parse($xml);
        $product = $parsed['categories'][0]['products'][0];
        $group = $product['option_groups'][0];
        $options = $group['options'];

        $this->assertSame('Tamanho', $group['name']);
        $this->assertCount(2, $options);
        $this->assertSame('Grande', $options[0]['name']);
        $this->assertSame('url', $options[0]['image']['type']);
        $this->assertSame('https://example.com/grande.jpg', $options[0]['image']['value']);
        $this->assertSame('Gigante', $options[1]['name']);
        $this->assertSame('https://example.com/gigante.jpg', $options[1]['image']['value']);
    }
}
