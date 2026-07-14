<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\EmissionPoint;
use App\Models\User;
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
            ],
        );

        // Usuario admin
        User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            ['name' => 'Administrador', 'password' => Hash::make('password123'), 'company_id' => $company->id],
        );

        // Punto de emisión demo
        EmissionPoint::firstOrCreate(
            ['company_id' => $company->id, 'estab' => '001', 'punto' => '001'],
            ['nombre' => 'Caja principal', 'secuencial' => 1],
        );

        // Plan de cuentas básico (NIIF PYME)
        $cuentas = [
            ['1', 'ACTIVO', 'activo'], ['1.1', 'ACTIVO CORRIENTE', 'activo'],
            ['1.1.01', 'Caja', 'activo'], ['1.1.02', 'Bancos', 'activo'],
            ['1.1.03', 'Cuentas por cobrar clientes', 'activo'], ['1.1.04', 'Credito tributario IVA', 'activo'],
            ['1.1.05', 'Inventario', 'activo'],
            ['2', 'PASIVO', 'pasivo'], ['2.1', 'PASIVO CORRIENTE', 'pasivo'],
            ['2.1.01', 'Cuentas por pagar proveedores', 'pasivo'], ['2.1.02', 'IVA por pagar', 'pasivo'],
            ['2.1.03', 'Retenciones por pagar', 'pasivo'],
            ['3', 'PATRIMONIO', 'patrimonio'], ['3.1.01', 'Capital', 'patrimonio'],
            ['4', 'INGRESOS', 'ingreso'], ['4.1.01', 'Ventas', 'ingreso'],
            ['5', 'GASTOS', 'gasto'], ['5.1.01', 'Compras', 'gasto'], ['5.1.02', 'Gastos generales', 'gasto'],
        ];
        foreach ($cuentas as [$cod, $nom, $tipo]) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'codigo' => $cod],
                ['nombre' => $nom, 'tipo' => $tipo],
            );
        }
    }
}
