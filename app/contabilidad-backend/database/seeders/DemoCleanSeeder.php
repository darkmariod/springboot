<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmissionPoint;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\GeneratePurchaseJournalEntry;
use App\Services\RegisterInventoryMovement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de demo LIMPIA — borra TODO (usuarios, contactos, productos, empleados,
 * bancos y data transaccional) y crea un escenario mínimo para la demo:
 *  - Usuario: Prueba Test (prueba@demo.com / password123, rol admin)
 *  - Empleado y cliente: PRUEBA TEST, cédula 0604196915
 *  - Proveedor genérico, producto de prueba con stock
 *
 * Run: php artisan db:seed --class=DemoCleanSeeder
 */
class DemoCleanSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrFail();

        // ── 1. Borrar datos transaccionales ──
        foreach ([
            'App\Models\JournalEntryLine', 'App\Models\JournalEntry',
            'App\Models\InventoryMovement', 'App\Models\SriDocument',
            'App\Models\InvoicePayment', 'App\Models\PurchasePayment',
            'App\Models\Withholding', 'App\Models\Quote', 'App\Models\BankMovement',
            'App\Models\ProductSerie', 'App\Models\CreditApplication',
            'App\Models\Advance', 'App\Models\CreditNote',
            'App\Models\Invoice', 'App\Models\Purchase', 'App\Models\PendingImport',
        ] as $model) {
            if (class_exists($model)) $model::query()->delete();
        }

        // ── 2. Borrar usuarios y catálogos (sin nombres reales) ──
        foreach ([
            Employee::class, Contact::class, Product::class, Bank::class, User::class,
        ] as $model) {
            if (class_exists($model)) $model::query()->delete();
        }

        // ── 3. Reset secuenciales ──
        $company->update(['secuencial' => 1]);
        $punto = EmissionPoint::firstOrCreate(
            ['company_id' => $company->id, 'estab' => '001', 'punto' => '001'],
            ['nombre' => 'Caja principal', 'secuencial' => 1],
        );
        $punto->update(['secuencial' => 1]);

        // ── 4. Usuario demo ──
        User::create([
            'name' => 'Prueba Test',
            'email' => 'prueba@demo.com',
            'password' => Hash::make('password123'),
            'company_id' => $company->id,
            'rol' => 'admin',
            'activo' => true,
        ]);

        // ── 5. Persona demo: empleado + cliente (cédula 0604196915) ──
        $cliente = Contact::create([
            'company_id' => $company->id,
            'identificacion' => '0604196915',
            'tipo_identificacion' => '05',
            'razon_social' => 'PRUEBA TEST',
            'email' => 'prueba@demo.com',
            'telefono' => '0999000000',
            'direccion' => 'Quito',
            'es_cliente' => true,
            'es_proveedor' => false,
        ]);

        if (class_exists(Employee::class)) {
            Employee::create([
                'company_id' => $company->id,
                'cedula' => '0604196915',
                'nombres' => 'PRUEBA TEST',
                'cargo' => 'Vendedor',
                'fecha_ingreso' => now()->subMonths(6)->toDateString(),
                'sueldo' => 500,
                'fondos_reserva' => true,
                'activo' => true,
            ]);
        }

        // ── 6. Proveedor genérico ──
        $proveedor = Contact::create([
            'company_id' => $company->id,
            'identificacion' => '1799999999999',
            'tipo_identificacion' => '04',
            'razon_social' => 'PROVEEDOR GENERICO S.A.',
            'direccion' => 'Av. Principal, Quito',
            'es_cliente' => false,
            'es_proveedor' => true,
        ]);

        // ── 7. Producto de prueba con stock ──
        $producto = Product::create([
            'company_id' => $company->id,
            'codigo' => 'PROD-001',
            'descripcion' => 'PRODUCTO PRUEBA',
            'tipo' => 'bien',
            'precio' => 10,
            'tarifa_iva' => 15,
            'maneja_series' => false,
            'stock' => 0,
            'costo_promedio' => 0,
        ]);

        $inv = app(RegisterInventoryMovement::class);
        $asiento = app(GeneratePurchaseJournalEntry::class);
        $items = [
            ['codigo_principal' => 'PROD-001', 'descripcion' => 'PRODUCTO PRUEBA', 'cantidad' => 10,
             'precio_unitario' => 5, 'base_imponible' => 50, 'tarifa' => 15, 'valor_iva' => 7.5],
        ];
        $purchase = Purchase::create([
            'company_id' => $company->id, 'contact_id' => $proveedor->id,
            'numero' => '001-001-000000001',
            'clave_acceso' => '0107202601179999999999910010010000000011234567890',
            'fecha_emision' => now()->subDays(3)->toDateString(), 'items' => $items,
            'total_sin_impuestos' => 50, 'total_impuesto' => 7.5,
            'importe_total' => 57.5, 'saldo_pendiente' => 57.5, 'xml' => null,
        ]);
        $inv->handle($producto, 'ingreso', 10, 5, 'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString());
        $asiento->handle($purchase);

        $this->command?->info('Demo limpia lista: usuario prueba@demo.com, cliente/empleado PRUEBA TEST, producto PROD-001 con stock 10.');
        $this->command?->info('Ejecutá: php artisan contable:chequeo');
    }
}
