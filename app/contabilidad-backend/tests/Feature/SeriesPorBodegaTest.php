<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.1 (S1 y S4): cada serie sabe en qué bodega está.
 *  - Una compra, un ajuste con sobrante o una transferencia fijan la bodega de la serie; una venta, un faltante y una
 *    devolución la respetan: una serie solo sale de la bodega en la que está.
 *  - Una serie anterior sin bodega (columna nula) cuenta como de la bodega principal (la marcada por defecto).
 *  - Transferir un producto con series obliga a elegir las series concretas (tantas como unidades) y las cambia de bodega.
 *  - Vender un producto con series exige tantas series como unidades.
 *  - Una nota de crédito con devolución parcial libera solo las series que regresan.
 */
class SeriesPorBodegaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;
    private Product $camara;
    private Warehouse $principal;
    private Warehouse $secundaria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Seguridad Electrónica SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->principal = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Bodega principal', 'por_defecto' => true]);
        $this->secundaria = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Sucursal Norte']);
        $this->cliente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Uno', 'es_cliente' => true]);
        $this->proveedor = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Distribuidora Hik', 'es_proveedor' => true]);
        $this->camara = Product::create([
            'company_id' => $this->company->id, 'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I',
            'tipo' => 'bien', 'maneja_series' => true, 'precio' => 150,
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------------ S1: la bodega entra con la compra

    public function test_una_compra_fija_la_bodega_de_cada_serie(): void
    {
        $this->comprar('001-001-000000001', ['A-1', 'A-2'], $this->secundaria)->assertCreated();
        $this->comprar('001-001-000000002', ['B-1']);   // sin bodega: la principal

        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'A-1')->value('warehouse_id'));
        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'A-2')->value('warehouse_id'));
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'B-1')->value('warehouse_id'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'A-1')->value('estado'));
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $this->auditoriaOk();
    }

    public function test_el_sobrante_de_un_ajuste_deja_las_series_nuevas_en_la_bodega_del_ajuste(): void
    {
        $this->comprar('001-001-000000001', ['A-1']);

        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->camara->id, 'warehouse_id' => $this->secundaria->id,
            'stock_fisico' => 2, 'motivo' => 'Conteo', 'series' => ['N-1', 'N-2'],
        ])->assertOk();

        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'N-1')->value('warehouse_id'));
        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'N-2')->value('warehouse_id'));
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'A-1')->value('warehouse_id'));
        $this->auditoriaOk();
    }

    public function test_una_serie_sin_bodega_cuenta_como_de_la_bodega_principal(): void
    {
        // Datos de antes de esta función: la serie no tiene bodega y su stock entró a la bodega por defecto
        app(RegisterInventoryMovement::class)->handle($this->camara, 'ingreso', 2, 100, 'Inventario inicial', '2026-01-01');
        ProductSerie::create(['company_id' => $this->company->id, 'product_id' => $this->camara->id, 'serie' => 'OLD-1', 'estado' => 'disponible']);
        ProductSerie::create(['company_id' => $this->company->id, 'product_id' => $this->camara->id, 'serie' => 'OLD-2', 'estado' => 'disponible']);
        $this->assertNull(ProductSerie::where('serie', 'OLD-1')->value('warehouse_id'));

        // El listado de la bodega principal las incluye; el de la otra bodega no
        $enPrincipal = $this->getJson('/api/series?company_id='.$this->company->id.'&product_id='.$this->camara->id.'&estado=disponible&warehouse_id='.$this->principal->id)->assertOk();
        $this->assertSame(['OLD-1', 'OLD-2'], collect($enPrincipal->json())->pluck('serie')->all());
        $enOtra = $this->getJson('/api/series?company_id='.$this->company->id.'&product_id='.$this->camara->id.'&estado=disponible&warehouse_id='.$this->secundaria->id)->assertOk();
        $this->assertSame([], $enOtra->json());

        // Y se pueden vender desde la bodega principal
        $this->vender(['OLD-1'])->assertCreated();
        $vendida = ProductSerie::where('serie', 'OLD-1')->first();
        $this->assertSame('vendida', $vendida->estado);
        $this->assertSame($this->principal->id, $vendida->warehouse_id, 'al salir queda anotada la bodega de la que salió');
    }

    // ------------------------------------------------------------------ S1: faltante

    public function test_faltante_con_una_serie_de_otra_bodega_devuelve_422_y_no_cambia_nada(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2']);                           // principal
        $this->comprar('001-001-000000002', ['S-1', 'S-2'], $this->secundaria);        // secundaria
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();

        $r = $this->ajustar($this->principal, 1, ['S-1'])->assertStatus(422);
        $this->assertSame('La serie S-1 no está en la bodega Bodega principal: está en la bodega Sucursal Norte.', $r->json('message'));

        $this->assertSame(4.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(4, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame($movimientos, InventoryMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
    }

    public function test_faltante_con_series_de_la_bodega_correcta_las_da_de_baja(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2']);
        $this->comprar('001-001-000000002', ['S-1', 'S-2', 'S-3'], $this->secundaria);

        $this->ajustar($this->secundaria, 1, ['S-1', 'S-3'])->assertOk()->assertJsonPath('tipo_movimiento', 'egreso')->assertJsonPath('cantidad', 2);

        $this->assertSame('danado', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame('danado', ProductSerie::where('serie', 'S-3')->value('estado'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-2')->value('estado'));
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(1.0, (float) WarehouseStock::where('warehouse_id', $this->secundaria->id)->value('stock'));
        $this->assertSame(2.0, (float) WarehouseStock::where('warehouse_id', $this->principal->id)->value('stock'));
        $this->auditoriaOk();
    }

    // ------------------------------------------------------------------ S1 + S4a: venta

    public function test_vender_una_serie_que_esta_en_otra_bodega_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', ['P-1']);
        $this->comprar('001-001-000000002', ['S-1'], $this->secundaria);

        // Sin bodega en la línea la venta sale de la principal: S-1 está en la otra
        $r = $this->vender(['S-1'])->assertStatus(422);
        $this->assertSame('La serie S-1 no está en la bodega Bodega principal: está en la bodega Sucursal Norte.', $r->json('message'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame(0, Invoice::count());

        // Con la bodega de la línea sí se vende, y la principal ya no sirve para esa serie
        $this->vender(['P-1'], null, $this->secundaria->id)->assertStatus(422);
        $this->vender(['S-1'], null, $this->secundaria->id)->assertCreated();
        $vendida = ProductSerie::where('serie', 'S-1')->first();
        $this->assertSame('vendida', $vendida->estado);
        $this->assertSame(Invoice::first()->id, $vendida->invoice_id);
        $this->assertSame(1.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(0.0, (float) WarehouseStock::where('warehouse_id', $this->secundaria->id)->sum('stock'));
        $this->auditoriaOk();
        $this->chequeoContableOk();
    }

    public function test_vender_con_un_numero_de_series_distinto_a_la_cantidad_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2', 'P-3']);

        $menos = $this->vender(['P-1', 'P-2'], 3)->assertStatus(422);
        $this->assertSame('El producto CAM-001 maneja series: la cantidad (3) debe coincidir con las series indicadas (2).', $menos->json('message'));
        $mas = $this->vender(['P-1', 'P-2', 'P-3'], 2)->assertStatus(422);
        $this->assertSame('El producto CAM-001 maneja series: la cantidad (2) debe coincidir con las series indicadas (3).', $mas->json('message'));
        $repetida = $this->vender(['P-1', 'P-1'], 2)->assertStatus(422);
        $this->assertSame('Hay series repetidas en la lista: P-1.', $repetida->json('message'));
        $this->vender([], 1)->assertStatus(422)->assertJsonPath('message', 'El producto CAM-001 maneja series; indique las series a vender.');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(3, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);

        $this->vender(['P-1', 'P-2'], 2)->assertCreated();
        $this->assertSame(1.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(1, ProductSerie::where('estado', 'disponible')->count());
    }

    // ------------------------------------------------------------------ S1: transferencia

    public function test_la_transferencia_mueve_exactamente_las_series_elegidas(): void
    {
        $this->comprar('001-001-000000001', ['T-1', 'T-2', 'T-3']);

        $this->transferir(2, ['T-1', 'T-3'])->assertOk();

        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'T-1')->value('warehouse_id'));
        $this->assertSame($this->secundaria->id, ProductSerie::where('serie', 'T-3')->value('warehouse_id'));
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'T-2')->value('warehouse_id'));
        $this->assertSame(3, ProductSerie::where('estado', 'disponible')->count(), 'siguen disponibles: solo cambian de bodega');
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(1.0, (float) WarehouseStock::where('warehouse_id', $this->principal->id)->value('stock'));
        $this->assertSame(2.0, (float) WarehouseStock::where('warehouse_id', $this->secundaria->id)->value('stock'));

        // Las series de cada bodega coinciden con el stock de cada bodega
        $this->assertSame(2, $this->seriesEn($this->secundaria));
        $this->assertSame(1, $this->seriesEn($this->principal));
        $this->auditoriaOk();
        $this->chequeoContableOk();

        // Ya no se pueden vender desde la bodega de origen, sí desde la nueva
        $this->vender(['T-1'])->assertStatus(422);
        $this->vender(['T-1'], null, $this->secundaria->id)->assertCreated();
    }

    public function test_la_transferencia_de_un_producto_con_series_exige_las_series_concretas(): void
    {
        $this->comprar('001-001-000000001', ['T-1', 'T-2', 'T-3']);
        $this->comprar('001-001-000000002', ['N-1'], $this->secundaria);
        $this->facturar(['T-3']);   // T-3 ya se vendió

        $sin = $this->transferir(2)->assertStatus(422);
        $this->assertSame('El producto CAM-001 maneja series: elige las 2 series que se transfieren (se indicaron 0).', $sin->json('message'));
        $pocas = $this->transferir(2, ['T-1'])->assertStatus(422);
        $this->assertSame('El producto CAM-001 maneja series: elige las 2 series que se transfieren (se indicaron 1).', $pocas->json('message'));
        $this->transferir(2, ['T-1', 'T-1'])->assertStatus(422)->assertJsonPath('message', 'Hay series repetidas en la lista: T-1.');
        $this->transferir(2, ['T-1', 'N-1'])->assertStatus(422)
            ->assertJsonPath('message', 'La serie N-1 no está en la bodega Bodega principal: está en la bodega Sucursal Norte.');
        $this->transferir(2, ['T-1', 'T-3'])->assertStatus(422)
            ->assertJsonPath('message', 'La serie T-3 no existe o ya fue vendida.');
        $this->transferir(1.5, ['T-1'])->assertStatus(422);

        // Nada se movió
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'T-1')->value('warehouse_id'));
        $this->assertSame(2, $this->seriesEn($this->principal));
        $this->assertSame(1, $this->seriesEn($this->secundaria));
        $this->assertSame(3, InventoryMovement::count(), 'las dos compras y la venta de T-3');
        $this->auditoriaOk();
    }

    public function test_la_transferencia_de_un_producto_sin_series_sigue_igual(): void
    {
        $tornillo = Product::create(['company_id' => $this->company->id, 'codigo' => 'TOR', 'descripcion' => 'Tornillos', 'tipo' => 'bien']);
        app(RegisterInventoryMovement::class)->handle($tornillo, 'ingreso', 10, 1, 'Inventario inicial', '2026-01-01', $this->principal->id);

        $this->postJson('/api/inventory/transferencia', [
            'company_id' => $this->company->id, 'product_id' => $tornillo->id, 'warehouse_origen_id' => $this->principal->id,
            'warehouse_destino_id' => $this->secundaria->id, 'cantidad' => 4, 'motivo' => 'Reabastecimiento',
        ])->assertOk();

        $this->assertSame(6.0, (float) WarehouseStock::where('warehouse_id', $this->principal->id)->where('product_id', $tornillo->id)->value('stock'));
        $this->assertSame(4.0, (float) WarehouseStock::where('warehouse_id', $this->secundaria->id)->where('product_id', $tornillo->id)->value('stock'));
        $this->auditoriaOk();
    }

    // ------------------------------------------------------------------ S4b: nota de crédito con devolución parcial

    public function test_la_devolucion_parcial_libera_solo_las_series_elegidas(): void
    {
        $this->comprar('001-001-000000001', ['A', 'B', 'C']);
        $factura = $this->facturar(['A', 'B', 'C']);
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);

        $this->devolver($factura, 1, ['B'])->assertCreated();

        $this->assertSame('vendida', ProductSerie::where('serie', 'A')->value('estado'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'B')->value('estado'));
        $this->assertSame('vendida', ProductSerie::where('serie', 'C')->value('estado'));
        $this->assertSame($factura->id, ProductSerie::where('serie', 'A')->value('invoice_id'));
        $this->assertNull(ProductSerie::where('serie', 'B')->value('invoice_id'));
        $this->assertSame(CreditNote::first()->id, ProductSerie::where('serie', 'B')->value('credit_note_id'));
        $this->assertSame(1.0, (float) $this->camara->fresh()->stock);
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'B')->value('warehouse_id'));
        $this->assertSame(['B'], CreditNote::first()->items[0]['series']);
        $this->auditoriaOk();
        $this->chequeoContableOk();

        // Una segunda devolución sin series: quedan A y C vendidas y se devuelve 1 -> no se sabe cuál
        $this->devolver($factura, 1)->assertStatus(422);
        $this->assertSame(1, CreditNote::count());
        $this->devolver($factura, 1, ['A'])->assertCreated();
        $this->assertSame('disponible', ProductSerie::where('serie', 'A')->value('estado'));
        $this->assertSame('vendida', ProductSerie::where('serie', 'C')->value('estado'));
    }

    public function test_la_devolucion_parcial_sin_series_pide_cuales_regresan_y_no_crea_nada(): void
    {
        $this->comprar('001-001-000000001', ['A', 'B', 'C']);
        $factura = $this->facturar(['A', 'B', 'C']);
        $asientos = JournalEntry::count();

        $r = $this->devolver($factura, 1)->assertStatus(422);
        $this->assertSame('Devuelves 1 de 3 unidades vendidas con series del producto CAM-001: indica cuáles series regresan (A, B, C).', $r->json('message'));

        $this->assertSame(0, CreditNote::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(0, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);
        $this->assertEquals($factura->saldo_pendiente, $factura->fresh()->saldo_pendiente);
    }

    public function test_la_devolucion_valida_que_las_series_sean_de_esa_factura_y_cuadren_con_la_cantidad(): void
    {
        $this->comprar('001-001-000000001', ['A', 'B', 'C', 'D']);
        $factura = $this->facturar(['A', 'B']);   // C y D siguen disponibles

        $this->devolver($factura, 2, ['A'])->assertStatus(422)
            ->assertJsonPath('message', 'El producto CAM-001: devuelves 2 unidades, indica exactamente 2 series (se indicaron 1).');
        $this->devolver($factura, 1, ['C'])->assertStatus(422)
            ->assertJsonPath('message', 'La serie C no se vendió en la factura '.$factura->numero.' o ya fue devuelta.');
        $this->devolver($factura, 2, ['A', 'A'])->assertStatus(422)
            ->assertJsonPath('message', 'Hay series repetidas en la lista: A.');

        $this->assertSame(0, CreditNote::count());
        $this->assertSame('vendida', ProductSerie::where('serie', 'A')->value('estado'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'C')->value('estado'));
    }

    public function test_la_devolucion_total_sin_series_libera_todas_como_antes(): void
    {
        $this->comprar('001-001-000000001', ['A', 'B']);
        $factura = $this->facturar(['A', 'B']);

        $this->devolver($factura, 2)->assertCreated();

        $this->assertSame(2, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
        $this->auditoriaOk();
        $this->chequeoContableOk();
    }

    public function test_anular_la_nota_vuelve_a_vender_solo_las_series_devueltas_y_las_deja_ligadas_a_la_factura(): void
    {
        $this->comprar('001-001-000000001', ['A', 'B', 'C']);
        $factura = $this->facturar(['A', 'B', 'C']);
        $nota = $this->devolver($factura, 1, ['B'])->assertCreated();

        $this->postJson('/api/credit-notes/'.$nota->json('id').'/anular')->assertOk();

        $this->assertSame(['vendida', 'vendida', 'vendida'], ProductSerie::orderBy('serie')->pluck('estado')->all());
        $this->assertSame($factura->id, ProductSerie::where('serie', 'B')->value('invoice_id'));
        $this->assertNull(ProductSerie::where('serie', 'B')->value('credit_note_id'));
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);
        $this->auditoriaOk();
    }

    public function test_anular_la_factura_devuelve_las_series_a_la_bodega_de_la_linea(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2'], $this->secundaria);
        $this->vender(['S-1'], null, $this->secundaria->id)->assertCreated();
        $factura = Invoice::firstOrFail();
        $this->assertSame(0.0, (float) WarehouseStock::where('warehouse_id', $this->principal->id)->sum('stock'));

        $this->postJson('/api/invoices/'.$factura->id.'/anular')->assertOk();

        $vuelta = ProductSerie::where('serie', 'S-1')->first();
        $this->assertSame('disponible', $vuelta->estado);
        $this->assertSame($this->secundaria->id, $vuelta->warehouse_id, 'vuelve a la bodega de la que salió, no a la principal');
        $this->assertNull($vuelta->invoice_id);
        $this->assertSame(2.0, (float) WarehouseStock::where('warehouse_id', $this->secundaria->id)->value('stock'));
        $this->assertSame(0, WarehouseStock::where('warehouse_id', $this->principal->id)->count());
        $this->auditoriaOk();
    }

    public function test_una_linea_con_una_bodega_de_otra_empresa_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', ['S-1']);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajena = Warehouse::create(['company_id' => $otra->id, 'codigo' => 'X1', 'nombre' => 'Bodega ajena']);

        $r = $this->vender(['S-1'], null, $ajena->id)->assertStatus(422);

        $this->assertSame('La bodega de la línea CAM-001 no existe en esta empresa.', $r->json('message'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-1')->value('estado'));
    }

    // ------------------------------------------------------------------ ayudas

    private function comprar(string $numero, array $series, ?Warehouse $bodega = null)
    {
        return $this->postJson('/api/purchases', array_filter([
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero, 'fecha_emision' => '2026-10-05',
            'warehouse_id' => $bodega?->id,
            'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'cantidad' => count($series),
                'precio_unitario' => 100, 'tarifa' => 15, 'series' => $series,
            ]],
        ], fn ($v) => $v !== null));
    }

    private function ajustar(Warehouse $bodega, float $fisico, array $series)
    {
        return $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->camara->id, 'warehouse_id' => $bodega->id,
            'stock_fisico' => $fisico, 'motivo' => 'Conteo', 'series' => $series,
        ]);
    }

    private function lineaVenta(array $series, ?float $cantidad = null, ?int $bodegaId = null): array
    {
        return array_filter([
            'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'cantidad' => $cantidad ?? count($series),
            'precio_unitario' => 150, 'tarifa' => 15, 'series' => $series, 'warehouse_id' => $bodegaId,
        ], fn ($v) => $v !== null);
    }

    private function vender(array $series, ?float $cantidad = null, ?int $bodegaId = null)
    {
        return $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [$this->lineaVenta($series, $cantidad, $bodegaId)],
        ]);
    }

    private function facturar(array $series): Invoice
    {
        $this->vender($series)->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    private function devolver(Invoice $factura, float $cantidad, ?array $series = null)
    {
        $linea = ['codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'cantidad' => $cantidad, 'precio_unitario' => 150, 'tarifa' => 15];
        if ($series !== null) {
            $linea['series'] = $series;
        }

        return $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Devolución de equipos', 'items' => [$linea],
        ]);
    }

    private function transferir(float $cantidad, ?array $series = null)
    {
        return $this->postJson('/api/inventory/transferencia', array_filter([
            'company_id' => $this->company->id, 'product_id' => $this->camara->id,
            'warehouse_origen_id' => $this->principal->id, 'warehouse_destino_id' => $this->secundaria->id,
            'cantidad' => $cantidad, 'motivo' => 'Reabastecimiento', 'series' => $series,
        ], fn ($v) => $v !== null));
    }

    private function seriesEn(Warehouse $bodega): int
    {
        return ProductSerie::where('product_id', $this->camara->id)->where('estado', 'disponible')->enBodega($bodega->id, $this->company->id)->count();
    }

    private function auditoriaOk(): void
    {
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    private function chequeoContableOk(): void
    {
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
    }
}
