<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Category;
use App\Models\Supplier;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Se agregó el $ a cada $this y se corrigieron las flechas ->
            'sku'         => strtoupper($this->faker->unique()->bothify('???-####')),
            'name'        => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'price'       => $this->faker->randomFloat(2, 10, 500),
            'cost'        => $this->faker->randomFloat(2, 5, 300),
            'stock'       => $this->faker->numberBetween(0, 100),
            'min_stock'   => $this->faker->numberBetween(0, 10),

            // Usamos funciones simples para obtener los IDs
            'category_id' => Category::inRandomOrder()->first()->id ?? Category::factory(),
            'supplier_id' => Supplier::inRandomOrder()->first()->id ?? Supplier::factory(),
        ];
    }
}
