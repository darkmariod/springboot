<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\EmissionPoint;
use App\Models\User;
use App\Support\Cuentas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Empresa demo
        $company = Company::firstOrCreate(
            ['ruc' => '1790000000001'],
            [
                'razon_social' => 'EMPRESA DEMO S.A.',
                'nombre_comercial' => 'Demo Contable',
                'dir_matriz' => 'Av. Principal 123, Quito',
                'estab' => '001', 'pto_emi' => '001', 'secuencial' => 1, 'ambiente' => 1,
                'plan' => 'completo',
            ],
        );

        // Usuario admin
        User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            ['name' => 'Administrador', 'password' => Hash::make('password123'), 'company_id' => $company->id, 'rol' => 'admin'],
        );

        // Usuario para la contadora (pedido del cliente) — rol contador
        User::firstOrCreate(
            ['email' => 'contador@demo.com'],
            ['name' => 'Contadora', 'password' => Hash::make('password123'), 'company_id' => $company->id, 'rol' => 'contador'],
        );

        // Punto de emisión demo
        EmissionPoint::firstOrCreate(
            ['company_id' => $company->id, 'estab' => '001', 'punto' => '001'],
            ['nombre' => 'Caja principal', 'secuencial' => 1],
        );

        // Plan de cuentas básico (NIIF PYME): sale de config/cuentas.php, el único lugar
        // donde cada concepto contable tiene su código.
        Cuentas::sembrar($company->id);
    }
}
