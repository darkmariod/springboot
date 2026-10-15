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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.2 (P3), cabos sueltos de la Fase 5a:
 *  (a) la pantalla de notas de crédito necesita las series VENDIDAS de la factura para elegir cuáles regresan;
 *  (b) una serie disponible no se marca dañada ni se elimina por las pantallas de Series: el stock no se movería y
 *      stock y series quedarían separados (para dar de baja una serie se usa Ajuste de inventario);
 *  (c) `inventario:auditar` compara las series disponibles con el stock de CADA bodega (una serie sin bodega cuenta
 *      como de la principal), no con el stock total.
 */
class SeriesCabosSueltosTest extends TestCase
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
            'company_id' => $this->company->id, 'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP', 'tipo' => 'bien', 'maneja_series' => true, 'precio' => 150,
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------------ (a) series vendidas de una factura

    public function test_se_pueden_pedir_las_series_vendidas_de_una_factura_para_elegir_las_que_regresan(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2', 'S-3', 'S-4']);
        $f1 = $this->facturar(['S-1', 'S-2', 'S-3']);
        $this->facturar(['S-4']);                                   // otra factura: sus series no deben salir

        $r = $this->getJson('/api/series?company_id='.$this->company->id.'&invoice_id='.$f1->id.'&estado=vendida')->assertOk();

        $this->assertSame(['S-1', 'S-2', 'S-3'], collect($r->json())->pluck('serie')->all());
        $this->assertSame(['CAM-001'], collect($r->json())->pluck('product.codigo')->unique()->values()->all());

        // Con una devuelta, ya no se ofrece
        $this->postJson('/api/credit-notes', $this->nota($f1, 1, ['S-2']))->assertCreated();
        $r = $this->getJson('/api/series?company_id='.$this->company->id.'&invoice_id='.$f1->id.'&estado=vendida')->assertOk();
        $this->assertSame(['S-1', 'S-3'], collect($r->json())->pluck('serie')->all());
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-2')->value('estado'));
    }

    // ------------------------------------------------------------------ (b) update y destroy

    public function test_marcar_danada_una_serie_disponible_responde_422_y_no_cambia_nada(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2']);
        $serie = ProductSerie::where('serie', 'S-1')->firstOrFail();
        $movimientos = InventoryMovement::count();

        $r = $this->putJson('/api/series/'.$serie->id, ['estado' => 'danado'])->assertStatus(422);

        $this->assertSame('Para dar de baja una serie usa Ajuste de inventario.', $r->json('message'));
        $this->assertSame('disponible', $serie->fresh()->estado);
        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
        $this->assertSame($movimientos, InventoryMovement::count());
        // Tampoco a otros estados que la sacarían del stock disponible sin mover el stock
        foreach (['vendida', 'devuelto'] as $estado) {
            $this->putJson('/api/series/'.$serie->id, ['estado' => $estado])->assertStatus(422);
        }
        $this->assertSame('disponible', $serie->fresh()->estado);
        $this->auditoriaOk();
    }

    public function test_eliminar_una_serie_disponible_responde_422_y_la_serie_sigue_ahi(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2']);
        $serie = ProductSerie::where('serie', 'S-1')->firstOrFail();

        $r = $this->deleteJson('/api/series/'.$serie->id)->assertStatus(422);

        $this->assertSame('Para dar de baja una serie usa Ajuste de inventario.', $r->json('message'));
        $this->assertNotNull(ProductSerie::find($serie->id));
        $this->assertSame(2, ProductSerie::where('estado', 'disponible')->count());
        $this->auditoriaOk();
    }

    public function test_dar_de_baja_la_serie_con_el_ajuste_de_inventario_sigue_funcionando(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2']);

        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->camara->id, 'warehouse_id' => $this->principal->id,
            'stock_fisico' => 1, 'motivo' => 'Se dañó', 'series' => ['S-1'],
        ])->assertOk();

        $this->assertSame('danado', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame(1.0, (float) $this->camara->fresh()->stock);
        $this->auditoriaOk();
    }

    public function test_los_demas_estados_conservan_su_comportamiento(): void
    {
        $this->comprar('001-001-000000001', ['S-1', 'S-2', 'S-3']);
        $factura = $this->facturar(['S-1']);

        // Cambios que no son de estado en una disponible: se permiten
        $s2 = ProductSerie::where('serie', 'S-2')->firstOrFail();
        $this->putJson('/api/series/'.$s2->id, ['estado' => 'disponible'])->assertOk();
        // Una vendida: sigue sin eliminarse y su estado sigue editable
        $s1 = ProductSerie::where('serie', 'S-1')->firstOrFail();
        $this->deleteJson('/api/series/'.$s1->id)->assertStatus(422)->assertJsonPath('message', 'No se puede eliminar una serie vendida.');
        $this->putJson('/api/series/'.$s1->id, ['estado' => 'devuelto'])->assertOk();
        $this->assertSame('devuelto', $s1->fresh()->estado);
        // Una dañada: se puede volver a disponible y se puede eliminar
        ProductSerie::where('id', $s2->id)->update(['estado' => 'danado']);
        $this->putJson('/api/series/'.$s2->id, ['estado' => 'disponible'])->assertOk();
        ProductSerie::where('id', $s2->id)->update(['estado' => 'danado']);
        $this->deleteJson('/api/series/'.$s2->id)->assertNoContent();
        $this->assertNull(ProductSerie::find($s2->id));
        $this->assertSame($factura->id, (int) $s1->fresh()->invoice_id);
    }

    // ------------------------------------------------------------------ (c) auditoría por bodega

    public function test_la_auditoria_pasa_cuando_las_series_cuadran_en_cada_bodega(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2']);
        $this->comprar('001-001-000000002', ['S-1', 'S-2', 'S-3'], $this->secundaria);
        $this->postJson('/api/inventory/transferencia', [
            'company_id' => $this->company->id, 'product_id' => $this->camara->id,
            'warehouse_origen_id' => $this->secundaria->id, 'warehouse_destino_id' => $this->principal->id, 'cantidad' => 1, 'series' => ['S-3'],
        ])->assertOk();

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_la_auditoria_cuenta_una_serie_sin_bodega_en_la_bodega_principal(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2']);
        ProductSerie::whereIn('serie', ['P-1', 'P-2'])->update(['warehouse_id' => null]);       // datos de antes de las bodegas en las series

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_la_auditoria_avisa_por_bodega_cuando_el_total_cuadra_pero_las_bodegas_no(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2']);                          // B01: 2 series y stock 2
        $this->comprar('001-001-000000002', ['S-1'], $this->secundaria);              // B02: 1 serie y stock 1
        // Una serie de B02 aparece anotada en B01: el total sigue en 3 series y 3 de stock, pero cada bodega está mal
        ProductSerie::where('serie', 'S-1')->update(['warehouse_id' => $this->principal->id]);

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])
            ->expectsOutputToContain('CAM-001  bodega B01 (Bodega principal): hay 3 series disponibles y el stock de esa bodega dice 2')
            ->expectsOutputToContain('CAM-001  bodega B02 (Sucursal Norte): hay 0 series disponibles y el stock de esa bodega dice 1')
            ->assertExitCode(1);
    }

    public function test_la_auditoria_sigue_avisando_cuando_faltan_series_en_una_sola_bodega(): void
    {
        $this->comprar('001-001-000000001', ['P-1', 'P-2', 'P-3']);
        ProductSerie::where('serie', 'P-3')->update(['estado' => 'danado']);           // 2 disponibles y el stock dice 3

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])
            ->expectsOutputToContain('CAM-001  bodega B01 (Bodega principal): hay 2 series disponibles y el stock de esa bodega dice 3')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------ ayudantes

    private function comprar(string $numero, array $series, ?Warehouse $bodega = null)
    {
        $this->postJson('/api/purchases', array_filter([
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero, 'fecha_emision' => '2026-10-05',
            'warehouse_id' => $bodega?->id,
            'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => count($series),
                'precio_unitario' => 100, 'tarifa' => 15, 'series' => $series,
            ]],
        ], fn ($v) => $v !== null))->assertCreated();
    }

    private function facturar(array $series): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => count($series),
                'precio_unitario' => 150, 'tarifa' => 15, 'series' => $series,
            ]],
        ])->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    private function nota(Invoice $factura, float $cantidad, array $series): array
    {
        return [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Devolución parcial',
            'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => $cantidad, 'precio_unitario' => 150, 'tarifa' => 15,
                'series' => $series,
            ]],
        ];
    }

    private function auditoriaOk(): void
    {
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }
}
