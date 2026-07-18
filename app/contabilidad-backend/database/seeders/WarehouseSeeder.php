<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        // Crear bodega por defecto para cada empresa que tenga productos
        $companyIds = Product::distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $bodega = Warehouse::firstOrCreate(
                ['company_id' => $companyId, 'codigo' => 'BG001'],
                ['nombre' => 'BODEGA GENERAL', 'por_defecto' => true, 'activa' => true]
            );

            // Migrar stock actual de products a warehouse_stocks
            $products = Product::where('company_id', $companyId)
                ->where('stock', '>', 0)
                ->get();

            foreach ($products as $product) {
                WarehouseStock::updateOrCreate(
                    ['warehouse_id' => $bodega->id, 'product_id' => $product->id],
                    ['stock' => $product->stock]
                );
            }

            $this->command->info("Bodega General creada para empresa {$companyId} con " . $products->count() . " productos con stock.");
        }
    }
}
