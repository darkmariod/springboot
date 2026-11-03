<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\EmissionPoint;
use App\Models\User;
use App\Models\Warehouse;
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

        // Usuario admin: es el operador de la plataforma (superadministrador, T11.6), el único que ve todas las empresas.
        // `es_superadmin` no es asignable en masa, por eso se fija aparte; si el usuario ya existía también queda como superadmin.
        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            ['name' => 'Administrador', 'password' => Hash::make('password123'), 'company_id' => $company->id, 'rol' => 'admin'],
        );
        $admin->forceFill(['es_superadmin' => true])->save();

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

        // Bodega principal: sin una, Ajuste de inventario, Fraccionamiento y Conversión no tienen bodega que elegir
        Warehouse::crearPrincipal($company);

        // Plan de cuentas básico (NIIF PYME): sale de config/cuentas.php, el único lugar
        // donde cada concepto contable tiene su código.
        Cuentas::sembrar($company->id);
    }
}
