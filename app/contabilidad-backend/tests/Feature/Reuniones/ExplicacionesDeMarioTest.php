<?php

namespace Tests\Feature\Reuniones;

use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockTransformation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Lo que Mario explicó el 7 de octubre: cómo debe comportarse el fraccionamiento de una presentación grande.
 */
#[Group('reuniones')]
class ExplicacionesDeMarioTest extends ReunionesTestCase
{
    private function valorInventario(): float
    {
        return round((float) Product::where('company_id', $this->company->id)->get()->sum(
            fn ($p) => (float) InventoryMovement::where('product_id', $p->id)->orderByDesc('fecha')->orderByDesc('id')->value('saldo_valor')
        ), 2);
    }

    /**
     * Pedido por: Mario (explicación del 7 de octubre)
     * Pedido: «Una caja que me costó 24 dólares trae 12 botellas: al abrirla quiero 12 botellas de 2 dólares, y que el valor del inventario no cambie.»
     * Se prueba: fraccionar 1 caja de $24 en 12 botellas deja cada botella a $2, la caja baja una unidad y el valor total del inventario queda igual.
     */
    #[Group('reuniones')]
    #[Group('R21')]
    public function test_R21_una_caja_de_24_dolares_con_12_unidades_da_12_unidades_de_2_dolares_y_el_inventario_no_cambia(): void
    {
        // Arrange: 10 cajas a $24 y el producto «botella» sin existencias
        $this->comprar($this->proveedor, [['CAJA', 10, 24]]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'BOT', 'descripcion' => 'Botella', 'tipo' => 'bien']);
        $antes = $this->valorInventario();
        $asientos = JournalEntry::count();
        $secuencial = (int) $this->company->fresh()->secuencial;
        $this->assertEquals(240.0, $antes);

        // Act: se abre una caja y salen 12 botellas
        $r = $this->postJson('/api/fractionations', [
            'company_id' => $this->company->id, 'product_id' => Product::where('codigo', 'CAJA')->value('id'),
            'child_product_id' => Product::where('codigo', 'BOT')->value('id'), 'quantity' => 1, 'units_per_parent' => 12,
        ])->assertCreated();

        // Assert: el documento dice 1 → 12 a $2 y $24 en total
        $r->assertJsonPath('numero', 'FRA-000001')->assertJsonPath('tipo', 'fraccionamiento');
        $this->assertEquals([1.0, 12.0, 2.0, 24.0], [$r->json('cantidad_origen'), $r->json('cantidad_destino'), $r->json('costo_unitario_destino'), $r->json('valor_total')]);
        $this->assertEquals(9.0, (float) Product::where('codigo', 'CAJA')->value('stock'));
        $botella = Product::where('codigo', 'BOT')->firstOrFail();
        $this->assertEquals(12.0, (float) $botella->stock);
        $this->assertEquals(2.0, (float) $botella->costo_promedio, 'Cada botella cuesta $2');
        // Assert: el valor del inventario no cambió ni un centavo, sin asiento y sin gastar el secuencial de las facturas
        $this->assertEquals($antes, $this->valorInventario());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial);
        $this->assertSame(1, StockTransformation::count());
        // Assert: el kárdex de la botella dice de dónde viene
        $fila = collect($this->getJson('/api/inventory/kardex/'.$botella->id)->assertOk()->json('movimientos'))->first();
        $this->assertStringContainsString('Fraccionamiento FRA-000001', $fila['concepto']);

        // Act 2 + Assert: vender 3 botellas registra el costo de $2 cada una
        $factura = $this->facturarBienes([['BOT', 3, 5]]);
        $this->assertEquals(6.0, $this->saldoMayor(['5.1.04']), 'Costo de ventas de 3 botellas a $2');
        $this->assertContabilidadCuadra();
    }
}
