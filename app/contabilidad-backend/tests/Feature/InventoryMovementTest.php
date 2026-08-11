<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_compra_registra_stock_costo_y_kardex(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01');

        $product->refresh();
        $this->assertSame(10.0, (float) $product->stock);
        $this->assertSame(5.0, (float) $product->costo_promedio);
        $this->assertCount(1, InventoryMovement::where('product_id', $product->id)->get());
    }

    public function test_segunda_compra_actualiza_costo_promedio_ponderado(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01');
        $this->servicio()->handle($product, 'ingreso', 5, 7, 'Compra 2', '2026-01-02');

        $product->refresh();
        $this->assertSame(15.0, (float) $product->stock);
        $this->assertEqualsWithDelta(5.6667, round((float) $product->costo_promedio, 4), 0.0001);
    }

    public function test_egreso_descuenta_stock_y_cuadra_la_bodega(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'codigo' => 'B01', 'nombre' => 'Bodega A']);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01', $warehouse->id);
        $this->servicio()->handle($product, 'egreso', 3, 5, 'Venta', '2026-01-03', $warehouse->id);

        $product->refresh();
        $this->assertSame(7.0, (float) $product->stock);
        $this->assertSame(2, InventoryMovement::where('product_id', $product->id)->count());
        $this->assertSame(7.0, (float) WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->stock);
    }

    public function test_egreso_que_deja_negativo_lanza_exception_y_no_muta_nada(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01');
        $this->servicio()->handle($product, 'egreso', 3, 5, 'Venta', '2026-01-03');
        $product->refresh();
        $this->assertSame(7.0, (float) $product->stock);

        try {
            $this->servicio()->handle($product, 'egreso', 8, 5, 'Venta', '2026-01-04');
            $this->fail('Debió lanzar RuntimeException por stock insuficiente.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Stock insuficiente de', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(7.0, (float) $product->stock);
        $this->assertSame(2, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_tipo_invalido_lanza_invalid_argument_exception(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);

        try {
            $this->servicio()->handle($product, 'salida', 1, 5, 'Raro', '2026-01-01');
            $this->fail('Debió lanzar InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Tipo de movimiento inválido', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(0.0, (float) $product->stock);
        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_transferencia_entre_bodegas_mantiene_stock_global(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);
        $a = Warehouse::create(['company_id' => $company->id, 'codigo' => 'B01', 'nombre' => 'Bodega A']);
        $b = Warehouse::create(['company_id' => $company->id, 'codigo' => 'B02', 'nombre' => 'Bodega B']);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01', $a->id);
        $this->servicio()->handle($product, 'egreso', 2, 5, 'Transferencia salida', '2026-01-02', $a->id);
        $this->servicio()->handle($product, 'ingreso', 2, 5, 'Transferencia entrada', '2026-01-02', $b->id);

        $product->refresh();
        $this->assertSame(10.0, (float) $product->stock);
        $this->assertSame(8.0, (float) WarehouseStock::where('warehouse_id', $a->id)->where('product_id', $product->id)->first()->stock);
        $this->assertSame(2.0, (float) WarehouseStock::where('warehouse_id', $b->id)->where('product_id', $product->id)->first()->stock);
    }

    public function test_series_egreso_exige_y_valida_series_y_las_marca_vendidas(): void
    {
        $company = $this->crearEmpresa();
        $contact = $this->crearContacto($company);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [],
            'total_sin_impuestos' => 0,
            'total_impuesto' => 0,
            'importe_total' => 0,
        ]);
        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'P-SERIE',
            'descripcion' => 'Producto con series',
            'stock' => 2,
            'costo_promedio' => 10,
            'maneja_series' => true,
        ]);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-001', 'estado' => 'disponible']);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-002', 'estado' => 'disponible']);

        try {
            $this->servicio()->handle($product, 'egreso', 1, 10, 'Venta', '2026-01-01', null, [], $invoice->id);
            $this->fail('Debió exigir series.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('indique las series', $e->getMessage());
        }

        try {
            $this->servicio()->handle($product, 'egreso', 1, 10, 'Venta', '2026-01-01', null, ['SR-999'], $invoice->id);
            $this->fail('Debió rechazar serie inexistente.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no existe o ya fue vendida', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(2.0, (float) $product->stock);
        $this->assertSame('disponible', ProductSerie::where('serie', 'SR-001')->first()->estado);

        $this->servicio()->handle($product, 'egreso', 1, 10, 'Venta', '2026-01-01', null, ['SR-001'], $invoice->id);

        $product->refresh();
        $this->assertSame(1.0, (float) $product->stock);
        $vendida = ProductSerie::where('serie', 'SR-001')->first();
        $this->assertSame('vendida', $vendida->estado);
        $this->assertSame($invoice->id, $vendida->invoice_id);
    }

    public function test_ingreso_libera_series_devueltas(): void
    {
        $company = $this->crearEmpresa();
        $contact = $this->crearContacto($company);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [],
            'total_sin_impuestos' => 0,
            'total_impuesto' => 0,
            'importe_total' => 0,
        ]);
        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'P-SERIE',
            'descripcion' => 'Producto con series',
            'stock' => 1,
            'costo_promedio' => 10,
            'maneja_series' => true,
        ]);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-001', 'estado' => 'vendida', 'invoice_id' => $invoice->id]);

        $this->servicio()->handle($product, 'ingreso', 1, 10, 'Devolución', '2026-01-02', null, ['SR-001']);

        $product->refresh();
        $this->assertSame(2.0, (float) $product->stock);
        $liberada = ProductSerie::where('serie', 'SR-001')->first();
        $this->assertSame('disponible', $liberada->estado);
        $this->assertNull($liberada->invoice_id);
    }

    public function test_contable_chequeo_pasa_sin_stock_negativo(): void
    {
        $company = $this->crearEmpresa();
        $product = $this->crearProducto($company);

        $this->servicio()->handle($product, 'ingreso', 10, 5, 'Compra 1', '2026-01-01');
        $this->servicio()->handle($product, 'egreso', 3, 5, 'Venta', '2026-01-03');

        $this->assertSame(0, Product::where('company_id', $company->id)->where('stock', '<', 0)->count());

        $this->artisan('contable:chequeo', ['--company' => $company->id])->assertExitCode(0);
    }

    private function servicio(): RegisterInventoryMovement
    {
        return app(RegisterInventoryMovement::class);
    }

    private function crearEmpresa(): Company
    {
        return Company::create([
            'ruc' => '1790000000001',
            'razon_social' => 'Empresa Test SA',
            'dir_matriz' => 'Av. Test 123',
            'estab' => '001',
            'pto_emi' => '001',
        ]);
    }

    private function crearProducto(Company $company): Product
    {
        return Product::create([
            'company_id' => $company->id,
            'codigo' => 'PROD-1',
            'descripcion' => 'Producto de prueba',
            'tipo' => 'bien',
        ]);
    }

    private function crearContacto(Company $company): Contact
    {
        return Contact::create([
            'company_id' => $company->id,
            'tipo_identificacion' => '05',
            'identificacion' => '1700000001',
            'razon_social' => 'Cliente Test',
        ]);
    }
}
