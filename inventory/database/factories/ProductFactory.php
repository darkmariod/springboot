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
            "sku" => strtoupper($this->faker->unique()->bothify("???-####")),
            "name" => $this->faker->word(),
            "description" => $this->faker->sentence(),
            "price" => $this->faker->randomFloat(2, 10, 500),
            "cost" => $this->faker->randomFloat(2, 5, 300),
            "stock" => $this->faker->numberBetween(0, 100),
            "min_stock" => $this->faker->numberBetween(0, 10),

            "category_id" =>
                Category::inRandomOrder()->first()->id ?? Category::factory(),
            "supplier_id" =>
                Supplier::inRandomOrder()->first()->id ?? Supplier::factory(),
        ];
    }
}
