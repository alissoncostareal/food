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
        $this->call(PlanSeeder::class);

        User::updateOrCreate(
            ['email' => 'admin@teste.com'],
            [
                'name' => 'Alisson Dev',
                'password' => bcrypt('12345678'),
                'role' => User::ROLE_SUPER_ADMIN,
            ]
        );

        // 3. Cria o usuário de teste padrão
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'role' => User::ROLE_CUSTOMER,
            ]
        );

        // 4. Cria 5 Lojas, e para cada loja, cria 10 produtos
        // Certifique-se de que o StoreFactory existe!
        if (Store::query()->count() === 0) {
            Store::factory(5)->create()->each(function ($store) {
                Product::factory(10)->create([
                    'store_id' => $store->id,
                ]);
            });
        }

        $this->command->info('Ambiente PartiuMenu semeado com sucesso!');
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
