<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;

class IfoodCatalogHomologationService
{
    public const HOMOLOG_CATEGORY_NAME = 'Teste Homologação';

    public const HOMOLOG_PRODUCT_NAME = 'Produto Teste';

    public function build(Store $store): array
    {
        $store->loadMissing('plan');

        $connected = $store->isIfoodConnected();

        $homologCategory = ProductCategory::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(self::HOMOLOG_CATEGORY_NAME)])
            ->first();

        $homologProduct = Product::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(self::HOMOLOG_PRODUCT_NAME)])
            ->with(['category', 'optionGroups.optionItems'])
            ->first();

        $scenario1 = $this->scenarioCategoryAndProduct($homologCategory, $homologProduct);
        $scenario2 = $this->scenarioOptionGroups($homologProduct);
        $scenario3 = $this->scenarioEditAndPause($homologProduct);

        $scenarios = [$scenario1, $scenario2, $scenario3];
        $completedCount = collect($scenarios)->filter(fn (array $scenario) => $scenario['complete'])->count();

        return [
            'connected' => $connected,
            'category_name' => self::HOMOLOG_CATEGORY_NAME,
            'product_name' => self::HOMOLOG_PRODUCT_NAME,
            'homolog_category_id' => $homologCategory?->id,
            'homolog_product_id' => $homologProduct?->id,
            'summary' => [
                'scenarios_total' => count($scenarios),
                'scenarios_complete' => $completedCount,
                'ready_for_review' => $connected && $completedCount === count($scenarios),
            ],
            'scenarios' => $scenarios,
        ];
    }

    private function scenarioCategoryAndProduct(?ProductCategory $category, ?Product $product): array
    {
        $steps = [
            $this->step(
                'category_created',
                'Criar categoria "'.self::HOMOLOG_CATEGORY_NAME.'" no PartiuMenu',
                $category instanceof ProductCategory,
                '/categories'
            ),
            $this->step(
                'category_published',
                'Publicar a categoria no iFood (botão Publicar em Categorias)',
                filled($category?->ifood_category_id),
                '/categories'
            ),
            $this->step(
                'product_created',
                'Criar produto "'.self::HOMOLOG_PRODUCT_NAME.'" com foto',
                $product instanceof Product && filled($product->image),
                '/products'
            ),
            $this->step(
                'product_published',
                'Publicar o produto no iFood (ícone de nuvem em Produtos)',
                filled($product?->ifood_item_id),
                '/products'
            ),
        ];

        return [
            'id' => 'scenario_1',
            'title' => 'Cenário 1 — Categoria e produto com foto',
            'description' => 'Cadastre a categoria e o produto de homologação com imagem e publique no iFood.',
            'steps' => $steps,
            'complete' => collect($steps)->every(fn (array $step) => $step['done']),
        ];
    }

    private function scenarioOptionGroups(?Product $product): array
    {
        $group = $product?->optionGroups?->first();
        $items = $group?->optionItems ?? collect();
        $itemsWithPhoto = $items->filter(fn ($item) => filled($item->getRawOriginal('image_url')));

        $steps = [
            $this->step(
                'option_group_created',
                'Adicionar um grupo de complementos ao produto de homologação',
                $group !== null,
                '/products'
            ),
            $this->step(
                'two_options_with_photo',
                'Cadastrar 2 complementos com foto no grupo',
                $items->count() >= 2 && $itemsWithPhoto->count() >= 2,
                '/products'
            ),
            $this->step(
                'options_published',
                'Republicar o produto no iFood após salvar os complementos',
                filled($product?->ifood_item_id) && $items->count() >= 2,
                '/products'
            ),
        ];

        return [
            'id' => 'scenario_2',
            'title' => 'Cenário 2 — Grupo e complementos com foto',
            'description' => 'No produto de homologação, crie um grupo com 2 complementos (com foto) e republica no iFood.',
            'steps' => $steps,
            'complete' => collect($steps)->every(fn (array $step) => $step['done']),
        ];
    }

    private function scenarioEditAndPause(?Product $product): array
    {
        $pausedOption = $product?->optionGroups
            ?->flatMap(fn ($group) => $group->optionItems)
            ->first(fn ($item) => $item->is_available === false);

        $steps = [
            $this->step(
                'product_was_published',
                'Produto de homologação já publicado no iFood',
                filled($product?->ifood_item_id),
                '/products'
            ),
            $this->step(
                'product_edited_and_republished',
                'Editar nome, foto ou preço e sincronizar no iFood (PATCH)',
                false,
                '/products',
                'Altere o produto em Produtos e clique no ícone de nuvem para sincronizar com o iFood.'
            ),
            $this->step(
                'option_paused',
                'Pausar um complemento (botão no modal de opcionais)',
                $pausedOption !== null,
                '/products'
            ),
            $this->step(
                'product_paused',
                'Pausar o produto no cardápio (sincroniza automaticamente com o iFood se já publicado)',
                $product instanceof Product && ! $product->is_active && filled($product->ifood_item_id),
                '/products'
            ),
        ];

        $autoSteps = collect($steps)->reject(fn (array $step) => $step['id'] === 'product_edited_and_republished');

        return [
            'id' => 'scenario_3',
            'title' => 'Cenário 3 — Editar e pausar',
            'description' => 'Edite o produto, republica, pause um complemento e pause o produto.',
            'steps' => $steps,
            'complete' => $autoSteps->every(fn (array $step) => $step['done']),
        ];
    }

    private function step(string $id, string $label, bool $done, string $route, ?string $hint = null): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'done' => $done,
            'route' => $route,
            'hint' => $hint,
        ];
    }
}
