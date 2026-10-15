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
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.2 (P2): el kárdex y el reporte de movimientos dicen DE DÓNDE vino cada movimiento: `origen_tipo` (clave estable),
 * `origen_label` (texto corto en español), `origen_numero` (el número del documento) y `origen_detalle` (proveedor,
 * cliente, motivo...). Sale de la relación real (compra, factura, documento de origen, asiento del ajuste); solo los
 * movimientos de antes, que no guardaban su documento, se reconocen por el número que lleva su concepto.
 */
class OrigenMovimientoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;
    private Warehouse $b01;
    private Warehouse $b02;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Origen Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Test',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        $this->b01 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->b02 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Secundaria']);
        Sanctum::actingAs(User::factory()->create());

        foreach (['A-001', 'B-002', 'C-003', 'D-004'] as $codigo) {
            Product::create(['company_id' => $this->company->id, 'codigo' => $codigo, 'descripcion' => $codigo, 'tipo' => 'bien']);
        }
        $this->comprar('001-001-000000001', [['A-001', 20, 10], ['B-002', 10, 4], ['C-003', 10, 6], ['D-004', 5, 8]]);
    }

    public function test_compra_dice_el_proveedor_y_el_numero_de_la_compra(): void
    {
        $f = $this->movimiento('A-001', 'ingreso', 'Compra 001-001-000000001');

        $this->assertSame(['compra', 'Compra', '001-001-000000001', 'Proveedor Test'], $this->origen($f));
    }

    public function test_venta_dice_el_numero_de_la_factura_y_el_cliente(): void
    {
        $factura = $this->facturar('A-001', 2);

        $f = $this->movimiento('A-001', 'egreso', 'Venta '.$factura->numero);

        $this->assertSame(['venta', 'Venta', $factura->numero, 'Cliente Test'], $this->origen($f));
        $this->assertSame($factura->id, (int) $f['invoice_id']);
    }

    public function test_anular_una_factura_dice_anulacion_de_factura_con_su_numero(): void
    {
        $factura = $this->facturar('A-001', 2);
        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        $f = $this->movimiento('A-001', 'ingreso', 'Anulación '.$factura->numero);

        $this->assertSame(['anulacion_factura', 'Anulación de factura', $factura->numero, 'Cliente Test'], $this->origen($f));
    }

    public function test_nota_de_credito_y_su_anulacion_apuntan_a_la_nota(): void
    {
        $factura = $this->facturar('B-002', 3);
        $this->postJson('/api/credit-notes', $this->nota($factura, 'B-002', 1, 'sri'))->assertCreated();
        $nc = CreditNote::where('invoice_id', $factura->id)->firstOrFail();

        $devolucion = $this->movimiento('B-002', 'ingreso', 'Devolución NC '.$nc->id);
        $this->assertSame(['nota_credito', 'Nota de crédito', 'NC-'.$nc->id, 'Cliente Test'], $this->origen($devolucion));
        // Desde ahora el movimiento guarda su nota (los de antes se reconocen por el número del concepto)
        $this->assertSame($nc->getMorphClass(), InventoryMovement::findOrFail($devolucion['id'])->origen_type);
        $this->assertSame($nc->id, (int) InventoryMovement::findOrFail($devolucion['id'])->origen_id);

        $this->postJson("/api/credit-notes/{$nc->id}/anular")->assertOk();
        $reverso = $this->movimiento('B-002', 'egreso', 'Reverso NC anulada '.$nc->id);
        $this->assertSame(['anulacion_nota_credito', 'Anulación de nota de crédito', 'NC-'.$nc->id, 'Cliente Test'], $this->origen($reverso));
    }

    public function test_una_nota_de_credito_interna_dice_su_numero_nci(): void
    {
        $factura = $this->facturar('B-002', 2);
        $this->postJson('/api/credit-notes', $this->nota($factura, 'B-002', 1, 'interna'))->assertCreated();
        $nc = CreditNote::where('invoice_id', $factura->id)->firstOrFail();

        $f = $this->movimiento('B-002', 'ingreso', 'Devolución NC '.$nc->id);

        $this->assertSame('NCI-000001', $nc->numero);
        $this->assertSame(['nota_credito', 'Nota de crédito', 'NCI-000001', 'Cliente Test'], $this->origen($f));
    }

    public function test_ajuste_de_inventario_dice_el_asiento_y_el_motivo(): void
    {
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->prod('C-003')->id, 'warehouse_id' => $this->b01->id,
            'stock_fisico' => 7, 'motivo' => 'Rotura en bodega',
        ])->assertOk();
        $m = InventoryMovement::where('concepto', 'like', 'Ajuste inventario%')->firstOrFail();
        $asiento = JournalEntry::where('origen_type', $m->getMorphClass())->where('origen_id', $m->id)->firstOrFail();

        $f = $this->movimiento('C-003', 'egreso', 'Ajuste inventario: Rotura en bodega');

        $this->assertSame(['ajuste', 'Ajuste de inventario', $asiento->numero, 'Rotura en bodega'], $this->origen($f));
    }

    public function test_toma_fisica_dice_su_numero_tf_y_la_bodega(): void
    {
        $id = $this->postJson('/api/physical-counts', ['company_id' => $this->company->id, 'warehouse_id' => $this->b01->id])->assertCreated()->json('id');
        $this->putJson("/api/physical-counts/{$id}", [
            'company_id' => $this->company->id, 'lineas' => [['product_id' => $this->prod('D-004')->id, 'contado' => 3]],
        ])->assertOk();
        $this->postJson("/api/physical-counts/{$id}/aplicar", ['company_id' => $this->company->id])->assertOk();

        $f = $this->movimiento('D-004', 'egreso', 'Toma física TF-000001');

        $this->assertSame(['toma_fisica', 'Toma física', 'TF-000001', 'Principal'], $this->origen($f));
    }

    public function test_transferencia_dice_si_fue_salida_o_entrada(): void
    {
        $this->postJson('/api/inventory/transferencia', [
            'company_id' => $this->company->id, 'product_id' => $this->prod('A-001')->id,
            'warehouse_origen_id' => $this->b01->id, 'warehouse_destino_id' => $this->b02->id, 'cantidad' => 4, 'motivo' => 'Surtir la sucursal',
        ])->assertOk();

        $salida = $this->movimiento('A-001', 'egreso', 'Transferencia salida: Surtir la sucursal');
        $entrada = $this->movimiento('A-001', 'ingreso', 'Transferencia entrada: Surtir la sucursal');

        $this->assertSame(['transferencia', 'Transferencia (salida)', null, 'Surtir la sucursal'], $this->origen($salida));
        $this->assertSame(['transferencia', 'Transferencia (entrada)', null, 'Surtir la sucursal'], $this->origen($entrada));
    }

    public function test_fraccionamiento_y_conversion_dicen_su_numero_y_los_productos(): void
    {
        $this->postJson('/api/fractionations', [
            'company_id' => $this->company->id, 'product_id' => $this->prod('A-001')->id, 'child_product_id' => $this->prod('B-002')->id,
            'quantity' => 1, 'units_per_parent' => 12,
        ])->assertCreated();
        $this->postJson('/api/conversions', [
            'company_id' => $this->company->id, 'product_from_id' => $this->prod('C-003')->id, 'product_to_id' => $this->prod('D-004')->id,
            'quantity_from' => 2, 'quantity_to' => 3,
        ])->assertCreated();

        $fraSale = $this->movimiento('A-001', 'egreso', 'Fraccionamiento FRA-000001');
        $fraEntra = $this->movimiento('B-002', 'ingreso', 'Fraccionamiento FRA-000001');
        $conSale = $this->movimiento('C-003', 'egreso', 'Conversión CONV-000001');

        $this->assertSame(['fraccionamiento', 'Fraccionamiento', 'FRA-000001', 'A-001 → B-002'], $this->origen($fraSale));
        $this->assertSame(['fraccionamiento', 'Fraccionamiento', 'FRA-000001', 'A-001 → B-002'], $this->origen($fraEntra));
        $this->assertSame(['conversion', 'Conversión', 'CONV-000001', 'C-003 → D-004'], $this->origen($conSale));
    }

    public function test_los_movimientos_de_antes_se_reconocen_por_el_numero_de_su_concepto(): void
    {
        $factura = $this->facturar('A-001', 1);
        $this->postJson('/api/credit-notes', $this->nota($factura, 'A-001', 1, 'sri'))->assertCreated();
        $nc = CreditNote::where('invoice_id', $factura->id)->firstOrFail();
        // Como estaban antes de guardar el documento de origen: sin origen_type ni origen_id
        InventoryMovement::where('concepto', 'Devolución NC '.$nc->id)->update(['origen_type' => null, 'origen_id' => null]);
        $this->movimientoSuelto('A-001', 'ingreso', 'Anulación '.$factura->numero);
        $this->movimientoSuelto('C-003', 'ingreso', 'Saldo inicial');
        $this->movimientoSuelto('C-003', 'ingreso', 'Inventario inicial 2025');
        $this->movimientoSuelto('D-004', 'egreso', 'Conversión 2025-03-02 a otro artículo');
        $this->movimientoSuelto('D-004', 'ingreso', 'Fraccionamiento de la caja');
        $this->movimientoSuelto('D-004', 'ingreso', 'Algo que nadie reconoce');

        $this->assertSame(['nota_credito', 'Nota de crédito', 'NC-'.$nc->id, 'Cliente Test'], $this->origen($this->movimiento('A-001', 'ingreso', 'Devolución NC '.$nc->id)));
        $this->assertSame(['anulacion_factura', 'Anulación de factura', $factura->numero, 'Cliente Test'], $this->origen($this->movimiento('A-001', 'ingreso', 'Anulación '.$factura->numero)));
        $this->assertSame(['saldo_inicial', 'Saldo inicial', null, null], $this->origen($this->movimiento('C-003', 'ingreso', 'Saldo inicial')));
        $this->assertSame(['saldo_inicial', 'Saldo inicial', null, null], $this->origen($this->movimiento('C-003', 'ingreso', 'Inventario inicial 2025')));
        $this->assertSame(['conversion', 'Conversión', null, null], $this->origen($this->movimiento('D-004', 'egreso', 'Conversión 2025-03-02 a otro artículo')));
        $this->assertSame(['fraccionamiento', 'Fraccionamiento', null, null], $this->origen($this->movimiento('D-004', 'ingreso', 'Fraccionamiento de la caja')));
        $this->assertSame(['otro', 'Otro movimiento', null, null], $this->origen($this->movimiento('D-004', 'ingreso', 'Algo que nadie reconoce')));
    }

    public function test_el_kardex_por_bodega_tambien_trae_el_origen(): void
    {
        $r = $this->getJson('/api/inventory/kardex/'.$this->prod('A-001')->id.'/bodega?warehouse_id='.$this->b01->id)->assertOk();

        $f = collect($r->json('movimientos'))->first();
        $this->assertSame(['compra', 'Compra', '001-001-000000001', 'Proveedor Test'], $this->origen($f));
    }

    public function test_el_reporte_de_movimientos_trae_origen_producto_y_bodega_y_se_puede_filtrar(): void
    {
        $factura = $this->facturar('A-001', 2);
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->prod('B-002')->id, 'warehouse_id' => $this->b01->id,
            'stock_fisico' => 12, 'motivo' => 'Sobrante hallado',
        ])->assertOk();

        $todos = $this->getJson('/api/inventory/movimientos?company_id='.$this->company->id)->assertOk();
        $filas = collect($todos->json('movimientos'));
        // 4 compras + 1 venta + 1 ajuste
        $this->assertCount(6, $filas);
        $venta = $filas->firstWhere('origen_tipo', 'venta');
        $this->assertSame(['A-001', 'Principal', 'egreso', 2.0, $factura->numero, 'Venta'], [
            $venta['codigo'], $venta['bodega'], $venta['tipo'], (float) $venta['cantidad'], $venta['origen_numero'], $venta['origen_label'],
        ]);
        $this->assertEquals(['ajuste' => 1, 'compra' => 4, 'venta' => 1], $filas->countBy('origen_tipo')->sortKeys()->all());
        $this->assertSame(6, $todos->json('resumen.movimientos'));

        // Filtro por clase de origen
        $soloCompras = collect($this->getJson('/api/inventory/movimientos?company_id='.$this->company->id.'&origen=compra')->assertOk()->json('movimientos'));
        $this->assertSame(['compra'], $soloCompras->pluck('origen_tipo')->unique()->values()->all());
        $this->assertCount(4, $soloCompras);
        // Filtro por producto
        $deB = collect($this->getJson('/api/inventory/movimientos?company_id='.$this->company->id.'&product_id='.$this->prod('B-002')->id)->assertOk()->json('movimientos'));
        $this->assertSame(['compra', 'ajuste'], $deB->pluck('origen_tipo')->all());
        // Filtro por fechas: las compras son de enero; hoy solo están la venta y el ajuste
        $hoy = now()->toDateString();
        $deHoy = collect($this->getJson('/api/inventory/movimientos?company_id='.$this->company->id."&desde={$hoy}&hasta={$hoy}")->assertOk()->json('movimientos'));
        $this->assertSame(['venta', 'ajuste'], $deHoy->pluck('origen_tipo')->all());
    }

    // ------------------------------------------------------------------------------------ ayudantes

    private function prod(string $codigo): Product
    {
        return Product::where('company_id', $this->company->id)->where('codigo', $codigo)->firstOrFail();
    }

    private function comprar(string $numero, array $lineas): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero, 'fecha_emision' => '2026-01-10',
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Purchase::where('numero', $numero)->firstOrFail();
    }

    private function facturar(string $codigo, float $cantidad): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'efectivo',
            'items' => [['codigo_principal' => $codigo, 'descripcion' => $codigo, 'cantidad' => $cantidad, 'precio_unitario' => 20, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function nota(Invoice $factura, string $codigo, float $cantidad, string $tipo): array
    {
        return [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => $tipo, 'motivo' => 'Devolución',
            'items' => [['codigo_principal' => $codigo, 'descripcion' => $codigo, 'cantidad' => $cantidad, 'precio_unitario' => 20, 'tarifa' => 15]],
        ];
    }

    /** Un movimiento viejo, sin documento de origen guardado: solo tiene el texto del concepto. */
    private function movimientoSuelto(string $codigo, string $tipo, string $concepto): void
    {
        InventoryMovement::create([
            'company_id' => $this->company->id, 'product_id' => $this->prod($codigo)->id, 'fecha' => '2025-01-02', 'tipo' => $tipo,
            'concepto' => $concepto, 'cantidad' => 1, 'costo_unitario' => 1, 'saldo_cantidad' => 0, 'saldo_costo_promedio' => 0, 'saldo_valor' => 0,
            'warehouse_id' => $this->b01->id,
        ]);
    }

    /** La fila del kárdex del producto (la API del kárdex) cuyo concepto empieza así. */
    private function movimiento(string $codigo, string $tipo, string $concepto): array
    {
        $r = $this->getJson('/api/inventory/kardex/'.$this->prod($codigo)->id)->assertOk();
        $filas = collect($r->json('movimientos'))->filter(fn ($m) => $m['tipo'] === $tipo && str_starts_with((string) $m['concepto'], $concepto))->values();
        $this->assertCount(1, $filas, "No hay un único movimiento '{$tipo}' de {$codigo} que empiece por «{$concepto}».");

        return $filas->first();
    }

    /** [tipo, etiqueta, número, detalle] de una fila del kárdex. */
    private function origen(array $fila): array
    {
        return [$fila['origen_tipo'], $fila['origen_label'], $fila['origen_numero'], $fila['origen_detalle']];
    }
}
