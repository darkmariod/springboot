<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T1.6: el ajuste de inventario de un producto con series no puede dar error 500
 * ni dejar el stock y las series descuadrados.
 *   Faltante: hay que indicar exactamente tantas series disponibles como unidades faltan.
 *   Sobrante: hay que indicar exactamente tantas series NUEVAS como unidades sobran.
 */
class AjusteInventarioSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Warehouse $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Series Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->bodega = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_faltante_con_series_las_da_de_baja_y_stock_y_series_quedan_iguales(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);   // costo 10

        $this->ajustar($producto, 1, 'Robo en bodega', ['S-1', 'S-3'])
            ->assertOk()
            ->assertJsonPath('tipo_movimiento', 'egreso')
            ->assertJsonPath('cantidad', 2);

        $this->assertSame(1.0, (float) $producto->fresh()->stock);
        $this->assertSame('danado', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame('danado', ProductSerie::where('serie', 'S-3')->value('estado'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-2')->value('estado'));
        $this->assertNull(ProductSerie::where('serie', 'S-1')->value('invoice_id'));
        // stock y series disponibles coinciden
        $this->assertSame(1, ProductSerie::where('product_id', $producto->id)->where('estado', 'disponible')->count());

        // El asiento del ajuste sigue igual: Debe Faltantes / Haber Inventario por 2 x 10
        $asiento = JournalEntry::where('origen_type', (new InventoryMovement)->getMorphClass())->firstOrFail();
        $lineas = $asiento->lines()->with('account')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->all();
        $this->assertEquals([['5.1.03', 20.0, 0.0], ['1.1.05', 0.0, 20.0]], $lineas);
    }

    public function test_sobrante_con_series_crea_las_series_nuevas_disponibles(): void
    {
        $producto = $this->productoConSeries(1, ['S-1']);

        $this->ajustar($producto, 3, 'Conteo físico', ['S-2', 'S-3'])
            ->assertOk()
            ->assertJsonPath('tipo_movimiento', 'ingreso');

        $this->assertSame(3.0, (float) $producto->fresh()->stock);
        $this->assertSame(3, ProductSerie::where('product_id', $producto->id)->where('estado', 'disponible')->count());
        $nueva = ProductSerie::where('serie', 'S-2')->firstOrFail();
        $this->assertSame($producto->id, $nueva->product_id);
        $this->assertSame($this->company->id, $nueva->company_id);
        $this->assertSame('disponible', $nueva->estado);

        $asiento = JournalEntry::where('origen_type', (new InventoryMovement)->getMorphClass())->firstOrFail();
        $lineas = $asiento->lines()->with('account')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->all();
        $this->assertEquals([['1.1.05', 20.0, 0.0], ['5.1.03', 0.0, 20.0]], $lineas);
    }

    public function test_faltante_sin_series_devuelve_422_y_no_cambia_nada(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);

        $this->ajustar($producto, 1, 'Faltante')
            ->assertStatus(422)
            ->assertJsonPath('message', "El producto P-SERIE maneja series: indica exactamente 2 series disponibles para dar de baja (se indicaron 0).");

        $this->sinCambios($producto, 3, 3);
    }

    public function test_faltante_con_cantidad_de_series_distinta_devuelve_422(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);

        $this->ajustar($producto, 1, 'Faltante', ['S-1'])->assertStatus(422)
            ->assertJsonPath('message', 'El producto P-SERIE maneja series: indica exactamente 2 series disponibles para dar de baja (se indicaron 1).');
        $this->ajustar($producto, 1, 'Faltante', ['S-1', 'S-2', 'S-3'])->assertStatus(422);

        $this->sinCambios($producto, 3, 3);
    }

    public function test_faltante_con_serie_inexistente_vendida_o_repetida_devuelve_422(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);
        ProductSerie::where('serie', 'S-3')->update(['estado' => 'vendida']);

        $this->ajustar($producto, 1, 'Faltante', ['S-1', 'S-999'])->assertStatus(422)
            ->assertJsonPath('message', 'La serie S-999 no existe o no está disponible para este producto.');
        $this->ajustar($producto, 1, 'Faltante', ['S-1', 'S-3'])->assertStatus(422)
            ->assertJsonPath('message', 'La serie S-3 no existe o no está disponible para este producto.');
        $this->ajustar($producto, 1, 'Faltante', ['S-1', 'S-1'])->assertStatus(422)
            ->assertJsonPath('message', 'Hay series repetidas en la lista: S-1.');

        $this->sinCambios($producto, 3, 2);
    }

    public function test_faltante_con_series_de_otro_producto_devuelve_422(): void
    {
        $producto = $this->productoConSeries(2, ['S-1', 'S-2']);
        $otro = Product::create(['company_id' => $this->company->id, 'codigo' => 'P-OTRO', 'descripcion' => 'Otro', 'tipo' => 'bien', 'maneja_series' => true]);
        ProductSerie::create(['company_id' => $this->company->id, 'product_id' => $otro->id, 'serie' => 'X-1', 'estado' => 'disponible']);

        $this->ajustar($producto, 1, 'Faltante', ['X-1'])->assertStatus(422);

        $this->assertSame('disponible', ProductSerie::where('serie', 'X-1')->value('estado'));
        $this->sinCambios($producto, 2, 2);
    }

    public function test_sobrante_sin_series_o_con_series_de_mas_o_de_menos_devuelve_422(): void
    {
        $producto = $this->productoConSeries(1, ['S-1']);

        $this->ajustar($producto, 3, 'Sobrante')->assertStatus(422)
            ->assertJsonPath('message', 'El producto P-SERIE maneja series: indica exactamente 2 series nuevas para el ingreso (se indicaron 0).');
        $this->ajustar($producto, 3, 'Sobrante', ['S-2'])->assertStatus(422);
        $this->ajustar($producto, 3, 'Sobrante', ['S-2', 'S-3', 'S-4'])->assertStatus(422);

        $this->sinCambios($producto, 1, 1);
    }

    public function test_sobrante_con_series_repetidas_o_que_ya_existen_devuelve_422(): void
    {
        $producto = $this->productoConSeries(1, ['S-1']);

        $this->ajustar($producto, 3, 'Sobrante', ['S-2', 'S-2'])->assertStatus(422)
            ->assertJsonPath('message', 'Hay series repetidas en la lista: S-2.');
        $this->ajustar($producto, 3, 'Sobrante', ['S-1', 'S-2'])->assertStatus(422)
            ->assertJsonPath('message', 'Estas series ya existen en el sistema: S-1.');

        $this->sinCambios($producto, 1, 1);
        $this->assertSame(0, ProductSerie::where('serie', 'S-2')->count());
    }

    public function test_producto_con_series_exige_unidades_enteras(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);

        $this->ajustar($producto, 1.5, 'Faltante', ['S-1', 'S-2'])->assertStatus(422)
            ->assertJsonPath('message', 'El producto P-SERIE maneja series: la diferencia debe ser un número entero de unidades.');

        $this->sinCambios($producto, 3, 3);
    }

    public function test_producto_sin_series_se_ajusta_igual_que_antes_y_ignora_las_series_enviadas(): void
    {
        $producto = Product::create(['company_id' => $this->company->id, 'codigo' => 'P-SIMPLE', 'descripcion' => 'Sin series', 'tipo' => 'bien']);
        app(RegisterInventoryMovement::class)->handle($producto, 'ingreso', 5, 4, 'Compra', '2026-01-01');

        $this->ajustar($producto, 3, 'Conteo')->assertOk()->assertJsonPath('tipo_movimiento', 'egreso');
        $this->assertSame(3.0, (float) $producto->fresh()->stock);

        // Aunque llegaran series, no se crea nada
        $this->ajustar($producto, 4, 'Conteo', ['X-1'])->assertOk()->assertJsonPath('tipo_movimiento', 'ingreso');
        $this->assertSame(4.0, (float) $producto->fresh()->stock);
        $this->assertSame(0, ProductSerie::count());
    }

    public function test_stock_en_bodega_informa_cuanto_hay_y_si_maneja_series(): void
    {
        $producto = $this->productoConSeries(3, ['S-1', 'S-2', 'S-3']);

        $this->getJson('/api/inventory/stock-bodega?product_id='.$producto->id.'&warehouse_id='.$this->bodega->id)
            ->assertOk()
            ->assertJsonPath('stock_bodega', 3)
            ->assertJsonPath('maneja_series', true);

        $otraBodega = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Secundaria']);
        $this->getJson('/api/inventory/stock-bodega?product_id='.$producto->id.'&warehouse_id='.$otraBodega->id)
            ->assertOk()->assertJsonPath('stock_bodega', 0);
    }

    // ------------------------------------------------------------- helpers

    /** Producto con series: $stock unidades entran por el kárdex (costo 10) y $series quedan disponibles. */
    private function productoConSeries(int $stock, array $series): Product
    {
        $producto = Product::create([
            'company_id' => $this->company->id, 'codigo' => 'P-SERIE',
            'descripcion' => 'Producto con series', 'tipo' => 'bien', 'maneja_series' => true,
        ]);
        app(RegisterInventoryMovement::class)->handle($producto, 'ingreso', $stock, 10, 'Compra', '2026-01-01');
        foreach ($series as $s) {
            ProductSerie::create(['company_id' => $this->company->id, 'product_id' => $producto->id, 'serie' => $s, 'estado' => 'disponible']);
        }

        return $producto;
    }

    private function ajustar(Product $producto, float $fisico, string $motivo, ?array $series = null)
    {
        return $this->postJson('/api/inventory/ajuste', array_filter([
            'company_id' => $this->company->id,
            'product_id' => $producto->id,
            'warehouse_id' => $this->bodega->id,
            'stock_fisico' => $fisico,
            'motivo' => $motivo,
            'series' => $series,
        ], fn ($v) => $v !== null));
    }

    /** Un ajuste rechazado no deja rastro: ni stock, ni movimiento, ni asiento, ni series nuevas. */
    private function sinCambios(Product $producto, float $stock, int $disponibles): void
    {
        $this->assertSame($stock, (float) $producto->fresh()->stock);
        $this->assertSame(1, InventoryMovement::where('product_id', $producto->id)->count());   // solo la compra inicial
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame($disponibles, ProductSerie::where('product_id', $producto->id)->where('estado', 'disponible')->count());
    }
}
