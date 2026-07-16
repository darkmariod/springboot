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
 * Demo scenario mirroring the KVS creator's video: a phone/computer store
 * with serialized products, a supplier purchase, sales (one by serie),
 * receivables, and bank movements ready for reconciliation.
 *
 * Run: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrFail();

        // ── 1. Clean transactional data ─────────────────────────────
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

        // ── 2. Catalog: contacts, bank, products (like the video) ──
        $proveedor = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1790012345001'],
            ['tipo_identificacion' => '04', 'razon_social' => 'DISTRIBUIDORA TECNOLOGICA S.A.',
             'direccion' => 'Av. Amazonas, Quito', 'es_proveedor' => true, 'es_cliente' => false]);
        $cliente = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1803553062001'],
            ['tipo_identificacion' => '05', 'razon_social' => 'EMILY ARMENDARIZ SERRANO',
             'email' => 'emily@mail.com', 'telefono' => '0999000001',
             'es_cliente' => true, 'es_proveedor' => false]);
        $clienteCredito = Contact::updateOrCreate(
            ['company_id' => $company->id, 'identificacion' => '1712345678'],
            ['tipo_identificacion' => '05', 'razon_social' => 'JUAN PEREZ',
             'email' => 'juan@mail.com', 'es_cliente' => true, 'es_proveedor' => false]);

        $banco = Bank::firstOrCreate(
            ['company_id' => $company->id, 'nombre' => 'Banco Pichincha'],
            ['numero_cuenta' => '2100012345', 'cuenta_contable' => '1.1.02']);

        $productos = [
            ['codigo' => '216', 'descripcion' => 'IPHONE 17 PRO MAX', 'precio' => 1000, 'series' => true],
            ['codigo' => '205', 'descripcion' => 'IPHONE 16', 'precio' => 800, 'series' => true],
            ['codigo' => '202', 'descripcion' => 'CUBO USB-C 20W', 'precio' => 12, 'series' => false],
            ['codigo' => 'PC-01', 'descripcion' => 'COMPUTADORA DE ESCRITORIO', 'precio' => 550, 'series' => true],
        ];
        $p = [];
        foreach ($productos as $x) {
            $p[$x['codigo']] = Product::updateOrCreate(
                ['company_id' => $company->id, 'codigo' => $x['codigo']],
                ['descripcion' => $x['descripcion'], 'tipo' => 'bien', 'precio' => $x['precio'],
                 'tarifa_iva' => 15, 'maneja_series' => $x['series'], 'stock' => 0, 'costo_promedio' => 0]);
        }

        // ── 3. Purchase: 5 iPhone 17 PM @600 + 10 cubos @5 (like the video) ──
        $inv = app(RegisterInventoryMovement::class);
        $asiento = app(GeneratePurchaseJournalEntry::class);
        $items = [
            ['codigo_principal' => '216', 'descripcion' => 'IPHONE 17 PRO MAX', 'cantidad' => 5,
             'precio_unitario' => 600, 'base_imponible' => 3000, 'tarifa' => 15, 'valor_iva' => 450],
            ['codigo_principal' => '202', 'descripcion' => 'CUBO USB-C 20W', 'cantidad' => 10,
             'precio_unitario' => 5, 'base_imponible' => 50, 'tarifa' => 15, 'valor_iva' => 7.5],
        ];
        $purchase = Purchase::create([
            'company_id' => $company->id, 'contact_id' => $proveedor->id,
            'numero' => '001-001-000000500',
            'clave_acceso' => '0107202601179001234500110010010000005001234567819',
            'fecha_emision' => now()->subDays(10)->toDateString(), 'items' => $items,
            'total_sin_impuestos' => 3050, 'total_impuesto' => 457.50,
            'importe_total' => 3507.50, 'saldo_pendiente' => 3507.50, 'xml' => null,
        ]);
        foreach ($items as $it) {
            $inv->handle($p[$it['codigo_principal']], 'ingreso', $it['cantidad'],
                $it['precio_unitario'], 'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString());
        }
        $asiento->handle($purchase);

        // Series of the 5 iPhones, linked to the purchase (warranty trace)
        foreach (['350269500001', '350269500002', '350269500003', '350269500004', '350269500005'] as $serie) {
            ProductSerie::create(['company_id' => $company->id, 'product_id' => $p['216']->id,
                'serie' => $serie, 'estado' => 'disponible', 'purchase_id' => $purchase->id]);
        }

        // ── 4. Sales ────────────────────────────────────────────────
        $emitter = app(InvoiceEmitter::class);
        // Sale by serie, paid by transfer (like the video: "entró a mi Pichincha")
        $venta1 = $emitter->emit($company, $cliente, [
            ['codigo_principal' => '216', 'descripcion' => 'IPHONE 17 PRO MAX',
             'cantidad' => 1, 'precio_unitario' => 1000, 'tarifa' => 15, 'series' => ['350269500001']],
        ], 'transferencia');
        ProductSerie::where('company_id', $company->id)->where('serie', '350269500001')
            ->update(['estado' => 'vendida', 'invoice_id' => $venta1->id]);

        // Credit sale (feeds receivables/aging demo)
        $emitter->emit($company, $clienteCredito, [
            ['codigo_principal' => '202', 'descripcion' => 'CUBO USB-C 20W',
             'cantidad' => 3, 'precio_unitario' => 12, 'tarifa' => 15],
        ], 'credito');

        // ── 5. Bank movements for reconciliation demo ──────────────
        BankMovement::create(['company_id' => $company->id, 'bank_id' => $banco->id,
            'fecha' => now()->subDays(2)->toDateString(), 'tipo' => 'credito', 'monto' => 1150,
            'concepto' => 'Transferencia venta '.$venta1->numero, 'conciliado' => true]);
        BankMovement::create(['company_id' => $company->id, 'bank_id' => $banco->id,
            'fecha' => now()->subDay()->toDateString(), 'tipo' => 'debito', 'monto' => 45.90,
            'concepto' => 'Comision mantenimiento cuenta', 'conciliado' => false]);

        // One employee so payroll can be generated live in the demo
        if (class_exists(Employee::class)) {
            Employee::updateOrCreate(
                ['company_id' => $company->id, 'cedula' => '1804567890'],
                ['nombres' => 'CARLOS VENDEDOR', 'cargo' => 'Vendedor',
                 'fecha_ingreso' => now()->subMonths(14)->toDateString(),
                 'sueldo' => 600, 'fondos_reserva' => true, 'activo' => true]);
        }

        $this->command?->info('Demo lista: compra con series, venta por serie, venta a credito, banco y empleado.');
    }
}
