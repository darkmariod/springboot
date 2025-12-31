<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Product::factory()
            ->count(20)
            ->make()
            ->each(function ($product){
                $product->category_id = Category::inRandomOrder()->first()->id;
                $product->supplier_id = Supplier::factory()->create()->id;
                $product->save();
            });
    }
}
