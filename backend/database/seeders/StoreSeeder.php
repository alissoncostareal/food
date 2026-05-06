<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Models\OptionGroup;
use App\Models\OptionItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Criar o Usuário Lojista
        $merchant = User::create([
            'name' => 'John da Pizzaria',
            'email' => 'pizzaria@teste.com',
            'password' => Hash::make('senha123'),
            'role' => 'store',
        ]);

        // 2. Criar a Loja vinculada a ele
        $store = Store::create([
            'user_id' => $merchant->id,
            'name' => 'Bella Napoli Pizzaria',
            'slug' => Str::slug('Bella Napoli Pizzaria'),
            'logo_url' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500',
            'address' => 'Rua das Flores, 123 - Centro',
            'delivery_fee' => 5.99,
            'is_open' => true,
        ]);

        // 3. Criar um Produto: Pizza de Calabresa
        $pizzaCalabresa = Product::create([
            'store_id' => $store->id,
            'name' => 'Pizza de Calabresa',
            'description' => 'Molho artesanal, muçarela, calabresa defumada, cebola e orégano.',
            'price' => 39.90,
            'image_url' => 'https://images.unsplash.com/photo-1534308983496-4fabb1a015ee?w=500',
            'is_available' => true,
        ]);

        // 4. Criar as Opções para a Pizza de Calabresa

        // Grupo A: Tamanho da Pizza (Obrigatório selecionar 1)
        $tamanhos = OptionGroup::create([
            'product_id' => $pizzaCalabresa->id,
            'name' => 'Escolha o tamanho',
            'min_selected' => 1,
            'max_selected' => 1,
        ]);

        OptionItem::create([
            'option_group_id' => $tamanhos->id,
            'name' => 'Média (6 fatias)',
            'price' => 0.00, // Preço base do produto
        ]);

        OptionItem::create([
            'option_group_id' => $tamanhos->id,
            'name' => 'Grande (8 fatias)',
            'price' => 10.00, // Adiciona +10.00 ao valor final
        ]);

        // Grupo B: Borda Recheada (Opcional, máximo 1)
        $bordas = OptionGroup::create([
            'product_id' => $pizzaCalabresa->id,
            'name' => 'Deseja borda recheada?',
            'min_selected' => 0,
            'max_selected' => 1,
        ]);

        OptionItem::create([
            'option_group_id' => $bordas->id,
            'name' => 'Borda de Catupiry',
            'price' => 6.00,
        ]);

        OptionItem::create([
            'option_group_id' => $bordas->id,
            'name' => 'Borda de Cheddar',
            'price' => 7.50,
        ]);

        // Grupo C: Remoção de Ingredientes (Opcional, livre escolha)
        $remocoes = OptionGroup::create([
            'product_id' => $pizzaCalabresa->id,
            'name' => 'Remover ingredientes?',
            'min_selected' => 0,
            'max_selected' => 3,
        ]);

        OptionItem::create([
            'option_group_id' => $remocoes->id,
            'name' => 'Sem Cebola',
            'price' => 0.00,
        ]);

        OptionItem::create([
            'option_group_id' => $remocoes->id,
            'name' => 'Sem Orégano',
            'price' => 0.00,
        ]);
    }
}
