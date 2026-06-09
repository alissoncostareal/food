<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::create([
            'id' => 1,
            'name' => 'Plano Bronze',
            'slug' => 'bronze',
            'price' => 0.00,
            'description' => 'Ideal para quem está começando. Até 20 produtos.',
            'max_products' => 20
        ]);

        \App\Models\Plan::create([
            'id' => 2,
            'name' => 'Plano Prata',
            'slug' => 'prata',
            'price' => 99.90,
            'description' => 'Para lojas em crescimento. Até 100 produtos.',
            'max_products' => 100
        ]);

        \App\Models\Plan::create([
            'id' => 3,
            'name' => 'Plano Ouro',
            'slug' => 'ouro',
            'price' => 199.90,
            'description' => 'Produtos ilimitados e suporte prioritário.',
            'max_products' => 0 // Ilimitado
        ]);
    }
}
