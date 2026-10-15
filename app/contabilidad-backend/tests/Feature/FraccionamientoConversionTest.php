<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\StockTransformation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.7: fraccionar (abrir una caja y vender sus unidades sueltas) y convertir (un producto pasa a ser otro) conservan
 * el VALOR del inventario: sale el padre a su costo promedio y entra el hijo al costo padre / unidades.
 *   - Cada operación deja un documento propio (FRA-000001 / CONV-000001, contador por empresa y por clase) y NO gasta
 *     el secuencial de la empresa (el de las facturas) ni crea asientos: Inventario -> Inventario no cambia el mayor.
 *   - Rechaza el mismo producto, stock insuficiente, productos de otra empresa, servicios, combos y productos con series.
 *   - Todo ocurre en una sola transacción: ante un rechazo no cambia nada.
 */
class FraccionamientoConversionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;
    private Warehouse $b01;
    private Warehouse $b02;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Fraccionamiento Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'secuencial' => 7,
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        $this->b01 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->b02 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Secundaria']);
        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->producto('CAJA');
        $this->producto('BOT');
    }

    // ------------------------------------------------------------ fraccionamiento: el ejemplo del pedido

    public function test_una_caja_de_24_dolares_en_12_botellas_deja_cada_botella_a_2_dolares_y_el_valor_igual(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->comprar([['BOT', 5, 3]], 2);                  // el hijo ya tenía existencias: 5 a $3 = $15
        $antes = $this->valorInventario();                   // 240 + 15
        $this->assertEquals(255.0, $antes);
        $asientos = JournalEntry::count();

        $r = $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('numero', 'FRA-000001')
            ->assertJsonPath('tipo', 'fraccionamiento')
            ->assertJsonPath('producto_origen', 'CAJA')
            ->assertJsonPath('producto_destino', 'BOT');
        $this->assertEquals(1.0, $r->json('cantidad_origen'));
        $this->assertEquals(12.0, $r->json('cantidad_destino'));
        $this->assertEquals(2.0, $r->json('costo_unitario_destino'));
        $this->assertEquals(24.0, $r->json('valor_total'));
        $this->assertEquals(0.0, $r->json('diferencia_redondeo'));

        // Stock: la caja baja 1, la botella sube 12
        $this->assertSame(9.0, $this->stock('CAJA'));
        $this->assertSame(17.0, $this->stock('BOT'));
        // La caja sigue a $24 y la botella queda con el promedio de 5 a $3 y 12 a $2 = $39 / 17
        $this->assertEquals(24.0, (float) $this->prod('CAJA')->costo_promedio);
        $this->assertEqualsWithDelta(39 / 17, (float) $this->prod('BOT')->costo_promedio, 0.0001);
        // El valor total del inventario no cambió ni un centavo
        $this->assertEquals($antes, $this->valorInventario());

        // Kárdex de los dos lados, con el número del documento en el concepto y el origen ligado
        $t = StockTransformation::firstOrFail();
        $salida = InventoryMovement::where('product_id', $this->prod('CAJA')->id)->where('tipo', 'egreso')->firstOrFail();
        $entrada = InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->latest('id')->firstOrFail();
        $this->assertSame('Fraccionamiento FRA-000001: 1 → 12 (a BOT)', $salida->concepto);
        $this->assertSame('Fraccionamiento FRA-000001: 1 → 12 (desde CAJA)', $entrada->concepto);
        $this->assertEquals(1.0, (float) $salida->cantidad);
        $this->assertEquals(24.0, (float) $salida->costo_unitario);
        $this->assertEquals(12.0, (float) $entrada->cantidad);
        $this->assertEquals(2.0, (float) $entrada->costo_unitario);
        foreach ([$salida, $entrada] as $m) {
            $this->assertSame($t->getMorphClass(), $m->origen_type);
            $this->assertSame($t->id, (int) $m->origen_id);
            $this->assertSame($this->b01->id, (int) $m->warehouse_id);
        }
        // El saldo del kárdex del hijo ya trae el valor correcto
        $this->assertEquals(39.0, (float) $entrada->saldo_valor);

        // El documento
        $this->assertSame('fraccionamiento', $t->tipo);
        $this->assertSame('FRA-000001', $t->numero);
        $this->assertSame($this->company->id, (int) $t->company_id);
        $this->assertSame($this->prod('CAJA')->id, (int) $t->product_from_id);
        $this->assertSame($this->prod('BOT')->id, (int) $t->product_to_id);
        $this->assertEquals(1.0, (float) $t->cantidad_origen);
        $this->assertEquals(12.0, (float) $t->cantidad_destino);
        $this->assertEquals(24.0, (float) $t->valor_total);
        $this->assertSame($this->b01->id, (int) $t->warehouse_id);
        $this->assertSame($this->usuario->id, (int) $t->user_id);

        // Stock por bodega
        $this->assertEquals(9.0, $this->stockBodega('CAJA', $this->b01));
        $this->assertEquals(17.0, $this->stockBodega('BOT', $this->b01));

        // Sin asiento y sin tocar el secuencial de las facturas
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(7, (int) $this->company->fresh()->secuencial);
    }

    public function test_el_numero_FRA_avanza_solo_y_no_toca_el_secuencial_de_la_empresa(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);

        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated()->assertJsonPath('numero', 'FRA-000001');
        $this->fraccionar(['quantity' => 2, 'units_per_parent' => 6])->assertCreated()->assertJsonPath('numero', 'FRA-000002');
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 4])->assertCreated()->assertJsonPath('numero', 'FRA-000003');

        $this->assertSame(7, (int) $this->company->fresh()->secuencial, 'El secuencial de las facturas no se gasta');
        $this->assertSame(['FRA-000001', 'FRA-000002', 'FRA-000003'], StockTransformation::orderBy('id')->pluck('numero')->all());
    }

    public function test_cada_empresa_y_cada_clase_llevan_su_propio_contador(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->producto('KIT');
        $this->producto('PIEZA');
        $this->comprar([['KIT', 4, 30]], 2);

        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated()->assertJsonPath('numero', 'FRA-000001');
        $this->convertir('KIT', 'PIEZA', 1, 3)->assertCreated()->assertJsonPath('numero', 'CONV-000001');
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated()->assertJsonPath('numero', 'FRA-000002');
        $this->convertir('KIT', 'PIEZA', 1, 3)->assertCreated()->assertJsonPath('numero', 'CONV-000002');

        // Otra empresa empieza en 1 sin importar lo que haya hecho la primera
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        Warehouse::create(['company_id' => $otra->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $padre = Product::create(['company_id' => $otra->id, 'codigo' => 'P', 'descripcion' => 'P', 'tipo' => 'bien']);
        $hijo = Product::create(['company_id' => $otra->id, 'codigo' => 'H', 'descripcion' => 'H', 'tipo' => 'bien']);
        app(RegisterInventoryMovement::class)->handle($padre, 'ingreso', 5, 10, 'Carga inicial', '2026-01-01');

        $this->postJson('/api/fractionations', [
            'company_id' => $otra->id, 'product_id' => $padre->id, 'child_product_id' => $hijo->id, 'quantity' => 1, 'units_per_parent' => 2,
        ])->assertCreated()->assertJsonPath('numero', 'FRA-000001');
    }

    public function test_cuando_la_division_no_es_exacta_el_hijo_entra_sin_inflar_el_valor(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);

        // 24 / 7 = 3.428571...: el costo se trunca a 4 decimales (3.4285), nunca se redondea hacia arriba
        $r = $this->fraccionar(['quantity' => 1, 'units_per_parent' => 7])->assertCreated();

        $this->assertEquals(3.4285, $r->json('costo_unitario_destino'));
        $this->assertEquals(24.0, $r->json('valor_total'));
        $this->assertEquals(0.0, $r->json('diferencia_redondeo'));
        $entrada = InventoryMovement::where('product_id', $this->prod('BOT')->id)->firstOrFail();
        $this->assertEquals(3.4285, (float) $entrada->costo_unitario);
        $this->assertEquals(24.0, (float) $entrada->saldo_valor, 'Los 7 a 3.4285 valen 23.9995 = 24.00');
    }

    public function test_si_el_costo_a_4_decimales_se_desvia_mas_de_un_centavo_el_hijo_entra_en_dos_tramos_exactos(): void
    {
        $this->producto('CAJA10');
        $this->comprar([['CAJA10', 2, 10]], 1);
        $antes = $this->valorInventario();                    // 20

        // 10 / 144 = 0.069444: con un solo costo de 0.0694 las 144 unidades valdrían 9.99 (un centavo menos)
        $r = $this->fraccionar(['product_id' => $this->prod('CAJA10')->id, 'quantity' => 1, 'units_per_parent' => 144])->assertCreated();

        $entradas = InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->orderBy('id')->get();
        $this->assertCount(2, $entradas, 'Dos tramos: 64 unidades a 0.0695 y 80 a 0.0694');
        $this->assertEquals(144.0, (float) $entradas->sum('cantidad'));
        $this->assertEquals(10.0, round($entradas->sum(fn ($m) => (float) $m->cantidad * (float) $m->costo_unitario), 2));
        $this->assertEquals(10.0, (float) $entradas->last()->saldo_valor);
        $this->assertEquals(10.0, $r->json('valor_total'));
        $this->assertEquals(0.0, $r->json('diferencia_redondeo'));
        $this->assertSame(144.0, $this->stock('BOT'));
        $this->assertEquals($antes, $this->valorInventario());
    }

    public function test_con_miles_de_unidades_el_valor_tampoco_se_desvia(): void
    {
        $this->producto('CAJA100');
        $this->comprar([['CAJA100', 1, 100]], 1);

        // 100 / 3000 = 0.033333: un solo costo daría 99.90 (diez centavos menos)
        $r = $this->fraccionar(['product_id' => $this->prod('CAJA100')->id, 'quantity' => 1, 'units_per_parent' => 3000])->assertCreated();

        $this->assertEquals(100.0, $r->json('valor_total'));
        $this->assertLessThanOrEqual(0.01, abs($r->json('diferencia_redondeo')));
        $entradas = InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->get();
        $this->assertEquals(3000.0, (float) $entradas->sum('cantidad'));
        $this->assertEqualsWithDelta(100.0, $entradas->sum(fn ($m) => round((float) $m->cantidad * (float) $m->costo_unitario, 2)), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $entradas->last()->saldo_valor, 0.01);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
        $this->chequeo()->expectsOutputToContain('OK  Fraccionamientos y conversiones (1)')->assertExitCode(0);
    }

    public function test_un_valor_justo_en_medio_centavo_nunca_infla_el_inventario(): void
    {
        $this->producto('CAJAX');
        $this->comprar([['CAJAX', 1, 41255]], 1);

        // 41255 / 454.5 = 90.7700770...: con el costo truncado 90.77 las 454.5 unidades valen 41254.965 (medio centavo)
        $r = $this->fraccionar(['product_id' => $this->prod('CAJAX')->id, 'quantity' => 1, 'units_per_parent' => 454.5])->assertCreated();

        $entradas = InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->get();
        $entra = round($entradas->sum(fn ($m) => round((float) $m->cantidad * (float) $m->costo_unitario, 2)), 2);
        $this->assertEquals(454.5, (float) $entradas->sum('cantidad'));
        $this->assertLessThanOrEqual(41255.0, $entra, 'Lo que entra jamás pasa de lo que salió');
        $this->assertGreaterThanOrEqual(41254.99, $entra);
        $this->assertGreaterThanOrEqual(0.0, $r->json('diferencia_redondeo'));
        $this->assertLessThanOrEqual(0.01, $r->json('diferencia_redondeo'));
    }

    public function test_fracciona_con_cantidades_decimales(): void
    {
        $this->producto('KILO');
        $this->comprar([['KILO', 10, 8]], 1);

        // 2.5 kilos a $8 = $20 en 10 porciones: $2 cada una
        $r = $this->fraccionar(['product_id' => $this->prod('KILO')->id, 'quantity' => 2.5, 'units_per_parent' => 4])->assertCreated();

        $this->assertEquals(10.0, $r->json('cantidad_destino'));
        $this->assertEquals(2.0, $r->json('costo_unitario_destino'));
        $this->assertEquals(20.0, $r->json('valor_total'));
        $this->assertSame(7.5, $this->stock('KILO'));
        $this->assertSame(10.0, $this->stock('BOT'));
    }

    public function test_sin_bodega_usa_la_que_tiene_el_stock_y_con_bodega_mueve_en_esa(): void
    {
        // El stock está solo en la bodega secundaria
        $caja = $this->prod('CAJA');
        app(RegisterInventoryMovement::class)->handle($caja, 'ingreso', 5, 24, 'Carga inicial', '2026-01-01', $this->b02->id);

        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();

        $this->assertEquals(4.0, $this->stockBodega('CAJA', $this->b02));
        $this->assertEquals(12.0, $this->stockBodega('BOT', $this->b02));
        $this->assertEquals(0.0, $this->stockBodega('BOT', $this->b01));

        // Eligiendo la bodega a mano
        app(RegisterInventoryMovement::class)->handle($caja, 'ingreso', 3, 24, 'Carga inicial', '2026-01-02', $this->b01->id);
        $this->fraccionar(['quantity' => 2, 'units_per_parent' => 6, 'warehouse_id' => $this->b01->id])->assertCreated()
            ->assertJsonPath('bodega', 'Principal');
        $this->assertEquals(1.0, $this->stockBodega('CAJA', $this->b01));
        $this->assertEquals(12.0, $this->stockBodega('BOT', $this->b01));
        $this->assertSame($this->b01->id, (int) StockTransformation::latest('id')->value('warehouse_id'));
    }

    public function test_el_kardex_muestra_el_numero_del_documento_en_el_concepto(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();

        $conceptos = collect($this->getJson('/api/inventory/kardex/'.$this->prod('BOT')->id)->assertOk()->json('movimientos'))->pluck('concepto')->all();
        $this->assertContains('Fraccionamiento FRA-000001: 1 → 12 (desde CAJA)', $conceptos);
    }

    // ------------------------------------------------------------ conversión

    public function test_la_conversion_con_cantidades_distintas_conserva_el_valor(): void
    {
        $this->producto('KIT');
        $this->producto('PIEZA');
        $this->comprar([['KIT', 4, 30]], 1);
        $antes = $this->valorInventario();                   // 120
        $asientos = JournalEntry::count();

        // 2 kits a $30 = $60 pasan a 5 piezas: $12 cada una (con el costo del kit para las 5 serían $150)
        $r = $this->convertir('KIT', 'PIEZA', 2, 5, 'Se desarma el kit')
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('numero', 'CONV-000001')
            ->assertJsonPath('tipo', 'conversion')
            ->assertJsonPath('producto_origen', 'KIT')
            ->assertJsonPath('producto_destino', 'PIEZA');
        $this->assertEquals(60.0, $r->json('valor_total'));
        $this->assertEquals(12.0, $r->json('costo_unitario_destino'));

        $this->assertSame(2.0, $this->stock('KIT'));
        $this->assertSame(5.0, $this->stock('PIEZA'));
        $this->assertEquals(12.0, (float) $this->prod('PIEZA')->costo_promedio);
        $this->assertEquals(60.0, round($this->stock('PIEZA') * (float) $this->prod('PIEZA')->costo_promedio, 2));
        $this->assertEquals($antes, $this->valorInventario());

        $t = StockTransformation::firstOrFail();
        $this->assertSame('conversion', $t->tipo);
        $this->assertSame('CONV-000001', $t->numero);
        $this->assertSame('Se desarma el kit', $t->motivo);
        $this->assertEquals(60.0, (float) $t->valor_total);

        $salida = InventoryMovement::where('product_id', $this->prod('KIT')->id)->where('tipo', 'egreso')->firstOrFail();
        $entrada = InventoryMovement::where('product_id', $this->prod('PIEZA')->id)->firstOrFail();
        $this->assertSame('Conversión CONV-000001: 2 → 5 (a PIEZA). Se desarma el kit', $salida->concepto);
        $this->assertSame('Conversión CONV-000001: 2 → 5 (desde KIT). Se desarma el kit', $entrada->concepto);
        $this->assertSame($t->id, (int) $entrada->origen_id);
        $this->assertSame($t->id, (int) $salida->origen_id);

        // Ya no deja un asiento vacío en borrador ni gasta el secuencial de las facturas
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(0, JournalEntry::where('numero', 'like', 'CONV-%')->count());
        $this->assertSame(7, (int) $this->company->fresh()->secuencial);
    }

    public function test_convertir_un_producto_en_otro_con_la_misma_cantidad_mueve_el_costo_completo(): void
    {
        $this->producto('MAL');
        $this->producto('BIEN');
        $this->comprar([['MAL', 3, 9.5]], 1);

        $this->convertir('MAL', 'BIEN', 3, 3, 'Código mal registrado')->assertCreated();

        $this->assertSame(0.0, $this->stock('MAL'));
        $this->assertSame(3.0, $this->stock('BIEN'));
        $this->assertEquals(9.5, (float) $this->prod('BIEN')->costo_promedio);
    }

    public function test_la_conversion_a_un_producto_con_existencias_mezcla_los_costos_y_conserva_el_total(): void
    {
        $this->producto('KIT');
        $this->producto('PIEZA');
        $this->comprar([['KIT', 3, 30]], 1);
        $this->comprar([['PIEZA', 4, 5]], 2);
        $antes = $this->valorInventario();                   // 90 + 20

        $this->convertir('KIT', 'PIEZA', 3, 9)->assertCreated();   // 90 / 9 = $10 cada una

        $this->assertSame(13.0, $this->stock('PIEZA'));
        $this->assertEquals(110.0, (float) InventoryMovement::where('product_id', $this->prod('PIEZA')->id)->orderByDesc('id')->value('saldo_valor'));
        $this->assertEquals($antes, $this->valorInventario());
    }

    // ------------------------------------------------------------ rechazos: nada cambia

    public function test_el_mismo_producto_da_422(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $foto = $this->foto();

        $this->fraccionar(['child_product_id' => $this->prod('CAJA')->id])->assertStatus(422);
        $this->convertir('CAJA', 'CAJA', 1, 1)->assertStatus(422);

        $this->assertSame($foto, $this->foto());
    }

    public function test_stock_insuficiente_da_422_con_la_cantidad_disponible_y_no_cambia_nada(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $foto = $this->foto();

        $r = $this->fraccionar(['quantity' => 11, 'units_per_parent' => 12])->assertStatus(422);
        $this->assertStringContainsString('Stock insuficiente', $r->json('message'));
        $this->assertStringContainsString('10', $r->json('message'));
        $this->assertEquals(10.0, $r->json('disponible'));

        $c = $this->convertir('CAJA', 'BOT', 11, 5)->assertStatus(422);
        $this->assertEquals(10.0, $c->json('disponible'));

        $this->assertSame($foto, $this->foto());
        $this->assertSame(10.0, $this->stock('CAJA'));
    }

    public function test_el_stock_se_mira_en_la_bodega_elegida(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);            // las 10 están en la bodega principal
        $foto = $this->foto();

        $r = $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12, 'warehouse_id' => $this->b02->id])->assertStatus(422);
        $this->assertStringContainsString('Secundaria', $r->json('message'));
        $this->assertEquals(0.0, $r->json('disponible'));

        $this->assertSame($foto, $this->foto());
    }

    public function test_una_bodega_de_otra_empresa_da_422(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajena = Warehouse::create(['company_id' => $otra->id, 'codigo' => 'X', 'nombre' => 'Ajena']);
        $foto = $this->foto();

        $this->fraccionar(['warehouse_id' => $ajena->id])->assertStatus(422);
        $this->convertir('CAJA', 'BOT', 1, 2, '', ['warehouse_id' => $ajena->id])->assertStatus(422);

        $this->assertSame($foto, $this->foto());
    }

    public function test_un_producto_de_otra_empresa_da_404_y_no_cambia_nada(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Product::create(['company_id' => $otra->id, 'codigo' => 'AJENO', 'descripcion' => 'Ajeno', 'tipo' => 'bien']);
        $foto = $this->foto();

        // Hijo ajeno
        $this->fraccionar(['child_product_id' => $ajeno->id])->assertNotFound();
        // Padre ajeno
        $this->fraccionar(['product_id' => $ajeno->id])->assertNotFound();
        $this->postJson('/api/conversions', [
            'company_id' => $this->company->id, 'product_from_id' => $this->prod('CAJA')->id, 'product_to_id' => $ajeno->id,
            'quantity_from' => 1, 'quantity_to' => 2,
        ])->assertNotFound();
        $this->postJson('/api/conversions', [
            'company_id' => $this->company->id, 'product_from_id' => $ajeno->id, 'product_to_id' => $this->prod('BOT')->id,
            'quantity_from' => 1, 'quantity_to' => 2,
        ])->assertNotFound();

        $this->assertSame($foto, $this->foto());
        $this->assertSame(0, StockTransformation::count());
    }

    public function test_un_servicio_o_un_combo_no_se_fracciona_ni_se_convierte(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $serv = Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        $combo = Product::create(['company_id' => $this->company->id, 'codigo' => 'COMBO', 'descripcion' => 'Combo', 'tipo' => 'bien', 'es_combo' => true]);
        $foto = $this->foto();

        $r = $this->fraccionar(['child_product_id' => $serv->id])->assertStatus(422);
        $this->assertStringContainsString('SERV', $r->json('message'));
        $this->assertStringContainsString('servicio', $r->json('message'));
        $this->fraccionar(['product_id' => $serv->id])->assertStatus(422);
        $this->convertir('CAJA', 'SERV', 1, 1)->assertStatus(422);
        $c = $this->fraccionar(['child_product_id' => $combo->id])->assertStatus(422);
        $this->assertStringContainsString('combo', $c->json('message'));

        $this->assertSame($foto, $this->foto());
    }

    public function test_un_producto_con_series_da_422_y_explica_por_que(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->producto('SERIE', ['maneja_series' => true]);
        $foto = $this->foto();

        $r = $this->fraccionar(['child_product_id' => $this->prod('SERIE')->id])->assertStatus(422);
        $this->assertStringContainsString('SERIE', $r->json('message'));
        $this->assertStringContainsString('series', $r->json('message'));
        $this->fraccionar(['product_id' => $this->prod('SERIE')->id])->assertStatus(422);
        $this->convertir('CAJA', 'SERIE', 1, 1)->assertStatus(422);
        $this->convertir('SERIE', 'CAJA', 1, 1)->assertStatus(422);

        $this->assertSame($foto, $this->foto());
    }

    public function test_faltan_datos_da_422(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);

        $this->postJson('/api/fractionations', ['company_id' => $this->company->id, 'product_id' => $this->prod('CAJA')->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['child_product_id', 'units_per_parent']);
        $this->postJson('/api/conversions', ['company_id' => $this->company->id, 'product_from_id' => $this->prod('CAJA')->id])
            ->assertStatus(422)->assertJsonValidationErrors(['product_to_id', 'quantity_from', 'quantity_to']);
        $this->fraccionar(['units_per_parent' => 0])->assertStatus(422);
        $this->fraccionar(['quantity' => 0])->assertStatus(422);
    }

    // ------------------------------------------------------------ chequeo contable

    public function test_el_chequeo_estricto_pasa_despues_de_fraccionar_y_convertir(): void
    {
        $this->producto('KIT');
        $this->producto('PIEZA');
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->comprar([['KIT', 4, 30]], 2);
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();
        $this->convertir('KIT', 'PIEZA', 2, 5)->assertCreated();

        $this->chequeo()
            ->expectsOutputToContain('OK  Fraccionamientos y conversiones (2): lo que sale del inventario es igual a lo que entra')
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);
        // Y el kárdex, el stock y las bodegas siguen cuadrando entre sí
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_los_movimientos_de_un_documento_de_transformacion_no_necesitan_asiento_aunque_el_concepto_cambie(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $t = $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();
        // El reconocimiento es por el documento de origen, no por el texto del concepto
        InventoryMovement::whereNotNull('origen_id')->update(['concepto' => 'Texto cualquiera']);

        $this->chequeo()->expectsOutputToContain('TODO OK')->assertExitCode(0);
        $this->assertNotNull($t->json('numero'));
    }

    public function test_el_chequeo_reporta_diferencia_si_una_transformacion_cambia_el_valor_del_inventario(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();
        // Fixture que rompe la regla: la entrada del hijo vale $30 contra $24 que salieron
        InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->update(['costo_unitario' => 2.5]);

        // Solo hace fallar con --estricto, como las demás revisiones de inventario
        $this->artisan('contable:chequeo', ['--company' => $this->company->id])
            ->expectsOutputToContain('DIFERENCIA  Fraccionamientos y conversiones sin el mismo valor: 1 de 1')
            ->assertExitCode(0);
        $this->chequeo()
            ->expectsOutputToContain('FRA-000001: sale $24.00, entra $30.00')
            ->assertExitCode(1);
    }

    public function test_el_chequeo_reporta_diferencia_si_a_una_transformacion_le_falta_un_lado(): void
    {
        $this->comprar([['CAJA', 10, 24]], 1);
        $this->fraccionar(['quantity' => 1, 'units_per_parent' => 12])->assertCreated();
        InventoryMovement::where('product_id', $this->prod('BOT')->id)->where('tipo', 'ingreso')->delete();

        $this->chequeo()
            ->expectsOutputToContain('DIFERENCIA  Fraccionamientos y conversiones sin el mismo valor: 1 de 1')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------ helpers

    private function producto(string $codigo, array $extra = []): Product
    {
        return Product::create(['company_id' => $this->company->id, 'codigo' => $codigo, 'descripcion' => $codigo, 'tipo' => 'bien'] + $extra);
    }

    private function prod(string $codigo): Product
    {
        return Product::where('company_id', $this->company->id)->where('codigo', $codigo)->firstOrFail();
    }

    private function stock(string $codigo): float
    {
        return (float) $this->prod($codigo)->stock;
    }

    private function stockBodega(string $codigo, Warehouse $bodega): float
    {
        return (float) WarehouseStock::where('product_id', $this->prod($codigo)->id)->where('warehouse_id', $bodega->id)->value('stock');
    }

    /** $lineas: [codigo, cantidad, costo]; IVA 15 %, el costo del kárdex es el neto. */
    private function comprar(array $lineas, int $n): void
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => '001-001-'.str_pad((string) $n, 9, '0', STR_PAD_LEFT), 'fecha_emision' => '2026-01-10',
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();
    }

    /** Fracciona CAJA en BOT por defecto; $extra reemplaza cualquier campo. */
    private function fraccionar(array $extra = [])
    {
        return $this->postJson('/api/fractionations', $extra + [
            'company_id' => $this->company->id, 'product_id' => $this->prod('CAJA')->id, 'child_product_id' => $this->prod('BOT')->id,
            'quantity' => 1, 'units_per_parent' => 12,
        ]);
    }

    private function convertir(string $origen, string $destino, float $cantOrigen, float $cantDestino, string $motivo = '', array $extra = [])
    {
        return $this->postJson('/api/conversions', $extra + [
            'company_id' => $this->company->id, 'product_from_id' => $this->prod($origen)->id, 'product_to_id' => $this->prod($destino)->id,
            'quantity_from' => $cantOrigen, 'quantity_to' => $cantDestino, 'motivo' => $motivo ?: null,
        ]);
    }

    /** Valor del inventario según el kárdex: el saldo en dinero de la última fila de cada producto. */
    private function valorInventario(): float
    {
        return round((float) Product::where('company_id', $this->company->id)->get()->sum(
            fn ($p) => (float) InventoryMovement::where('product_id', $p->id)->orderByDesc('fecha')->orderByDesc('id')->value('saldo_valor')
        ), 2);
    }

    /** Todo lo que un rechazo no debe tocar. */
    private function foto(): array
    {
        return [
            'movimientos' => InventoryMovement::count(),
            'documentos' => StockTransformation::count(),
            'asientos' => JournalEntry::count(),
            'secuencial' => (int) $this->company->fresh()->secuencial,
            'stock' => Product::orderBy('id')->pluck('stock', 'codigo')->map(fn ($s) => (float) $s)->all(),
            'bodegas' => WarehouseStock::orderBy('id')->get(['warehouse_id', 'product_id', 'stock'])->toArray(),
        ];
    }

    private function chequeo()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }
}
