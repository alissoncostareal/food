<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        return [
            'user_id' => \App\Models\User::factory(), // Cria um dono automaticamente
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name), // Gera o slug do nome
            'address' => fake()->address(),
            'description' => fake()->sentence(),
            'delivery_fee' => fake()->randomFloat(2, 0, 15),
            'is_open' => fake()->boolean(),
        ];
    }
}
