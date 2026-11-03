<?php

namespace Tests\Feature\Reuniones;

use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use PHPUnit\Framework\Attributes\Group;

/**
 * La contadora anterior: lo que dejó dicho sobre cómo debe tratarse la mercadería en los libros.
 * El ajuste de inventario genera su asiento solo y la mercadería es un activo (Inventario), no un gasto.
 */
#[Group('reuniones')]
class ContadoraAnteriorTest extends ReunionesTestCase
{
    /**
     * Pedido por: CONT
     * Pedido: «Cuando se ajusta el inventario por un faltante o un sobrante, el asiento tiene que salir solo, sin que yo lo haga a mano.»
     * Se prueba: un faltante de 3 unidades a $5 y un sobrante de 1 dejan sus asientos automáticos contra Inventario, con el motivo en el kárdex.
     */
    #[Group('reuniones')]
    #[Group('R20')]
    public function test_R20_el_ajuste_de_inventario_genera_su_asiento_automatico(): void
    {
        // Arrange: 10 unidades a $5 (costo $50)
        $this->comprar($this->proveedor, [['MERC-1', 10, 5]]);
        $bodega = \App\Models\Warehouse::where('company_id', $this->company->id)->where('por_defecto', true)->firstOrFail();
        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $asientos = JournalEntry::count();

        // Act 1: el conteo físico dice 7 (faltan 3)
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $producto->id, 'warehouse_id' => $bodega->id,
            'stock_fisico' => 7, 'motivo' => 'Rotura en bodega',
        ])->assertOk();

        // Assert 1: sale un asiento solo, el stock queda en 7 y el kárdex dice el motivo
        $this->assertSame($asientos + 1, JournalEntry::count());
        $movimiento = InventoryMovement::where('concepto', 'like', 'Ajuste inventario%')->firstOrFail();
        $this->assertSame('egreso', $movimiento->tipo);
        $this->assertEquals(3.0, (float) $movimiento->cantidad);
        $asiento = $this->asientoDe($movimiento);
        $this->assertEquals(15.0, (float) $asiento->total_debe, '3 unidades × $5');
        $this->assertLineas($asiento, [['5.1.03', 15, 0], ['1.1.05', 0, 15]]);   // Debe diferencias de inventario / Haber Inventario
        $this->assertEquals(7.0, (float) $producto->fresh()->stock);
        $fila = collect($this->getJson('/api/inventory/kardex/'.$producto->id)->assertOk()->json('movimientos'))->firstWhere('tipo', 'egreso');
        $this->assertSame(['ajuste', 'Ajuste de inventario', $asiento->numero, 'Rotura en bodega'], [$fila['origen_tipo'], $fila['origen_label'], $fila['origen_numero'], $fila['origen_detalle']]);

        // Act 2: el conteo dice 8 (sobra 1)
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $producto->id, 'warehouse_id' => $bodega->id,
            'stock_fisico' => 8, 'motivo' => 'Unidad hallada',
        ])->assertOk();

        // Assert 2: el sobrante entra a Inventario con su asiento
        $this->assertSame($asientos + 2, JournalEntry::count());
        $sobrante = InventoryMovement::where('concepto', 'like', 'Ajuste inventario%')->where('tipo', 'ingreso')->firstOrFail();
        $this->assertLineas($this->asientoDe($sobrante), [['1.1.05', 5, 0], ['5.1.03', 0, 5]]);   // el sobrante entra a Inventario
        $this->assertEquals(8.0, (float) $producto->fresh()->stock);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONT
     * Pedido: «La mercadería que compro va a Inventario, no a gasto; y cuando vendo, el sistema tiene que registrar el costo de lo vendido.»
     * Se prueba: compra de 10 a $5 debita 1.1.05 (no 5.1.01); venta de 4 a $10 registra costo de ventas $20 contra Inventario en el mismo asiento.
     */
    #[Group('reuniones')]
    #[Group('R20')]
    public function test_R20_la_mercaderia_va_a_inventario_y_la_venta_registra_el_costo(): void
    {
        // Act 1: compra de bienes y de un servicio
        $compra = $this->comprar($this->proveedor, [['MERC-1', 10, 5], ['SERV-1', 1, 40]]);

        // Assert 1: el bien a Inventario; solo el servicio es gasto
        $this->assertLineas($this->asientoDe($compra), [['1.1.05', 50, 0], ['5.1.01', 40, 0], ['1.1.04', 13.5, 0], ['2.1.01', 0, 103.5]]);
        $this->assertEquals(50.0, $this->saldoMayor(['1.1.05']), 'El inventario vale lo que costó');

        // Act 2: se venden 4 unidades a $10, en efectivo
        $factura = $this->facturarBienes([['MERC-1', 4, 10]]);

        // Assert 2: un solo asiento con la venta y el costo (4 × $5 = $20)
        $this->assertLineas($this->asientoDe($factura), [
            ['1.1.01', 46, 0], ['4.1.01', 0, 40], ['2.1.02', 0, 6],
            ['5.1.04', 20, 0], ['1.1.05', 0, 20],
        ]);
        $this->assertEquals(30.0, $this->saldoMayor(['1.1.05']), 'Quedan 6 unidades a $5');
        $this->assertEquals(20.0, $this->saldoMayor(['5.1.04']), 'Costo de ventas');
        $this->assertEquals(6.0, (float) Product::where('codigo', 'MERC-1')->value('stock'));

        // Act 3: una nota de crédito con devolución revierte el costo con el costo al que salió
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'interna', 'motivo' => 'Devolución',
            'items' => [['codigo_principal' => 'MERC-1', 'descripcion' => 'MERC-1', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();
        $this->assertEquals(15.0, $this->saldoMayor(['5.1.04']), 'Costo de ventas: 20 − 5 de la unidad devuelta');
        $this->assertEquals(35.0, $this->saldoMayor(['1.1.05']), 'La unidad devuelta vuelve a Inventario a su costo');
        $this->assertEquals(7.0, (float) Product::where('codigo', 'MERC-1')->value('stock'));
        $this->assertContabilidadCuadra();
    }
}
