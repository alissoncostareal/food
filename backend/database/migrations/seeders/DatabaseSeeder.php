<?php

namespace Database\Seeders;

use App\Models\OperatingHour;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
       User::factory()->create([
            'name' => 'Alisson Dev',
            'email' => 'admin@teste.com',
            'password' => bcrypt('12345678'),
            'role' => 'admin',
        ]);

        // 3. Cria o usuário de teste padrão
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // 4. Cria 5 Lojas, e para cada loja, cria 10 produtos
        // Certifique-se de que o StoreFactory existe!
        \App\Models\Store::factory(5)->create()->each(function ($store) {
            \App\Models\Product::factory(10)->create([
                'store_id' => $store->id,
            ]);
        });

        $this->command->info('Ambiente de Marketplace semeado com sucesso!');
    }

    public function seedOperatingHours($storeId)
    {
        for ($i = 0; $i <= 6; $i++) {
            OperatingHour::create([
                'store_id' => $storeId,
                'day_of_week' => $i,
                'opening_time' => '08:00:00',
                'closing_time' => '22:00:00',
                'is_closed' => ($i === 0) ? true : false, // Exemplo: Fecha aos domingos
            ]);
        }
    }
}
