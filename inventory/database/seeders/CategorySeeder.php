<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
        'General Requirements',  // Requisitos generales
        'Site Construction',     // Preparación del sitio
        'Concrete',              // Concreto / Hormigón
        'Masonry',               // Mampostería (Ladrillos/Bloques)
        'Metals',                // Metales y Estructuras metálicas
        'Wood and Plastics',     // Madera y Plásticos
        'Thermal and Moisture Protection', // Aislamiento e Impermeabilización
        'Doors and Windows',     // Puertas y Ventanas
        'Finishes',              // Acabados (Pintura, Suelos, Cielos)
        'Specialties',           // Especialidades
        'Equipment',             // Equipamiento
        'Furnishings',           // Mobiliario
        'Conveying Systems',     // Sistemas de transporte (Elevadores)
        'Mechanical / HVAC',     // Mecánica y Aire Acondicionado
        'Electrical',            // Instalaciones Eléctricas
        'Plumbing',              // Plomería / Fontanería
    ];

    foreach ($categories as $category) {
        \App\Models\Category::create([
            'name' => $category,
        ]);
    }
}
}
