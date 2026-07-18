<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Purchase;
use App\Models\Employee;
use App\Services\GeneratePurchaseJournalEntry;
use App\Services\InvoiceEmitter;
use App\Services\RegisterInventoryMovement;
use Illuminate\Database\Seeder;

/**
 * Seeder de prueba limpio — sin nombres reales.
 * Solo datos genéricos para verificar que todo funciona.
 *
 * Run: php artisan db:seed --class=TestSeeder
 */
class TestSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrFail();

        // ── Limpiar datos transaccionales ──
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
        Product::query()->update(['stock' => 0, 'costo_promedio' => 0]);
        $company->update(['secuencial' => 1]);

        // ── Contactos genéricos (sin nombres reales) ──
        $proveedor = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1799999999999'],
            ['tipo_identificacion' => '04', 'razon_social' => 'PROVEEDOR TEST S.A.',
             'direccion' => 'Av. Principal, Quito', 'es_proveedor' => true, 'es_cliente' => false]);

        $cliente = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1888888888888'],
            ['tipo_identificacion' => '05', 'razon_social' => 'CLIENTE GENERICO',
             'email' => 'cliente@test.com', 'telefono' => '0999000000',
             'es_cliente' => true, 'es_proveedor' => false]);

        $clienteCredito = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1777777777777'],
            ['tipo_identificacion' => '05', 'razon_social' => 'CLIENTE CREDITO TEST',
             'email' => 'credito@test.com', 'es_cliente' => true, 'es_proveedor' => false]);

        // ── Banco ──
        $banco = Bank::firstOrCreate(
            ['company_id' => $company->id, 'nombre' => 'Banco Test'],
            ['numero_cuenta' => '2100000000', 'cuenta_contable' => '1.1.02']);

        // ── Productos genéricos ──
        $productos = [
            ['codigo' => 'PROD-001', 'descripcion' => 'PRODUCTO TEST A', 'precio' => 100, 'series' => false],
            ['codigo' => 'PROD-002', 'descripcion' => 'PRODUCTO TEST B', 'precio' => 50, 'series' => false],
            ['codigo' => 'PROD-003', 'descripcion' => 'PRODUCTO TEST C', 'precio' => 200, 'series' => true],
        ];
        $p = [];
        foreach ($productos as $x) {
            $p[$x['codigo']] = Product::updateOrCreate(
                ['company_id' => $company->id, 'codigo' => $x['codigo']],
                ['descripcion' => $x['descripcion'], 'tipo' => 'bien', 'precio' => $x['precio'],
                 'tarifa_iva' => 15, 'maneja_series' => $x['series'], 'stock' => 0, 'costo_promedio' => 0]);
        }

        // ── Compra: 10 PROD-001 @60 + 5 PROD-002 @20 ──
        $inv = app(RegisterInventoryMovement::class);
        $asiento = app(GeneratePurchaseJournalEntry::class);
        $items = [
            ['codigo_principal' => 'PROD-001', 'descripcion' => 'PRODUCTO TEST A', 'cantidad' => 10,
             'precio_unitario' => 60, 'base_imponible' => 600, 'tarifa' => 15, 'valor_iva' => 90],
            ['codigo_principal' => 'PROD-002', 'descripcion' => 'PRODUCTO TEST B', 'cantidad' => 5,
             'precio_unitario' => 20, 'base_imponible' => 100, 'tarifa' => 15, 'valor_iva' => 15],
        ];
        $purchase = Purchase::create([
            'company_id' => $company->id, 'contact_id' => $proveedor->id,
            'numero' => '001-001-000000001',
            'clave_acceso' => '0107202601179999999999910010010000000011234567890',
            'fecha_emision' => now()->subDays(5)->toDateString(), 'items' => $items,
            'total_sin_impuestos' => 700, 'total_impuesto' => 105,
            'importe_total' => 805, 'saldo_pendiente' => 805, 'xml' => null,
        ]);
        foreach ($items as $it) {
            $inv->handle($p[$it['codigo_principal']], 'ingreso', $it['cantidad'],
                $it['precio_unitario'], 'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString());
        }
        $asiento->handle($purchase);

        // ── Venta 1: contado ──
        $emitter = app(InvoiceEmitter::class);
        $venta1 = $emitter->emit($company, $cliente, [
            ['codigo_principal' => 'PROD-001', 'descripcion' => 'PRODUCTO TEST A',
             'cantidad' => 2, 'precio_unitario' => 100, 'tarifa' => 15],
        ], 'efectivo');

        // ── Venta 2: crédito ──
        $venta2 = $emitter->emit($company, $clienteCredito, [
            ['codigo_principal' => 'PROD-002', 'descripcion' => 'PRODUCTO TEST B',
             'cantidad' => 1, 'precio_unitario' => 50, 'tarifa' => 15],
        ], 'credito');

        // ── Banco: un movimiento ──
        BankMovement::create(['company_id' => $company->id, 'bank_id' => $banco->id,
            'fecha' => now()->subDay()->toDateString(), 'tipo' => 'credito', 'monto' => 230,
            'concepto' => 'Transferencia venta '.$venta1->numero, 'conciliado' => false]);

        // ── Empleado genérico ──
        if (class_exists(Employee::class)) {
            Employee::updateOrCreate(
                ['company_id' => $company->id, 'cedula' => '1800000000'],
                ['nombres' => 'EMPLEADO TEST', 'cargo' => 'Vendedor',
                 'fecha_ingreso' => now()->subMonths(12)->toDateString(),
                 'sueldo' => 500, 'fondos_reserva' => true, 'activo' => true]);
        }

        $this->command?->info('TestSeeder listo: 1 compra, 2 ventas (1 contado, 1 crédito), 1 banco, 1 empleado.');
        $this->command?->info('Ejecutá: php artisan contable:chequeo');
    }
}
