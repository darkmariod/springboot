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
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StorePurchaseFromXml;
use App\Support\SeriesRequeridas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.1 (S2 y S3): las series entran con la compra y se buscan por su número.
 *  - Un producto con series exige tantas series como unidades al comprar (a mano o con el XML del proveedor): el stock nunca
 *    sube sin sus series. Una serie repetida o que ya existe da 422 en español.
 *  - El XML de una factura con productos con series no puede inventar las series: sin ellas responde 422 con la lista de líneas
 *    que las necesitan; con ellas importa. Los productos sin series se importan como siempre.
 *  - GET series/buscar encuentra una serie por un pedazo de su número y trae su historial; Productos encuentra al dueño de la serie.
 */
class SeriesCompraBusquedaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;
    private Contact $cliente;
    private Product $camara;
    private Product $mouse;
    private Warehouse $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Seguridad Electrónica SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->principal = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Bodega principal', 'por_defecto' => true]);
        $this->proveedor = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Distribuidora Hik', 'es_proveedor' => true]);
        $this->cliente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Ferretería El Clavo', 'es_cliente' => true]);
        $this->camara = Product::create([
            'company_id' => $this->company->id, 'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I',
            'tipo' => 'bien', 'maneja_series' => true, 'precio' => 150,
        ]);
        $this->mouse = Product::create(['company_id' => $this->company->id, 'codigo' => 'MOU-001', 'descripcion' => 'Mouse óptico', 'tipo' => 'bien', 'precio' => 8]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------------ S2: compra manual

    public function test_comprar_3_unidades_con_2_series_devuelve_422_y_no_crea_nada(): void
    {
        $r = $this->comprar('001-001-000000001', 3, ['SN-1', 'SN-2'])->assertStatus(422);

        $this->assertSame(
            ['El producto CAM-001 maneja series: indica exactamente 3 series, una por unidad (se indicaron 2).'],
            $r->json('errors.series')
        );
        $this->sinRastro();
    }

    public function test_comprar_2_unidades_con_3_series_tambien_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', 2, ['SN-1', 'SN-2', 'SN-3'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto CAM-001 maneja series: indica exactamente 2 series, una por unidad (se indicaron 3).');
        $this->comprar('001-001-000000002', 2, [])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto CAM-001 maneja series: indica exactamente 2 series, una por unidad (se indicaron 0).');
        $this->sinRastro();
    }

    public function test_comprar_3_unidades_con_3_series_crea_la_compra_y_el_stock_es_igual_a_las_series_disponibles(): void
    {
        $r = $this->comprar('001-001-000000001', 3, ['SN-1', ' SN-2 ', 'SN-3'])->assertCreated();

        $compra = Purchase::findOrFail($r->json('id'));
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $series = ProductSerie::where('product_id', $this->camara->id)->orderBy('serie')->get();
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], $series->pluck('serie')->all(), 'las series se guardan sin espacios');
        $this->assertSame(3, $series->where('estado', 'disponible')->count());
        $this->assertSame((float) $this->camara->fresh()->stock, (float) $series->where('estado', 'disponible')->count());
        $this->assertSame([$compra->id], $series->pluck('purchase_id')->unique()->values()->all());
        $this->assertSame([$this->principal->id], $series->pluck('warehouse_id')->unique()->values()->all());
        $this->assertSame($this->company->id, $series->first()->company_id);
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], $compra->items[0]['series'], 'la compra guarda las series de su línea');

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
    }

    public function test_una_serie_repetida_en_la_compra_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', 3, ['SN-1', 'SN-2', 'SN-1'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'Hay series repetidas en la compra: SN-1.');
        $this->sinRastro();
    }

    public function test_una_serie_que_ya_existe_devuelve_422_con_el_producto_dueno(): void
    {
        $this->comprar('001-001-000000001', 2, ['SN-1', 'SN-2'])->assertCreated();
        $otra = Product::create(['company_id' => $this->company->id, 'codigo' => 'NVR-001', 'descripcion' => 'Grabador NVR', 'tipo' => 'bien', 'maneja_series' => true]);

        $this->comprar('001-001-000000002', 2, ['SN-2', 'SN-9'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'Estas series ya existen en el sistema: SN-2 (producto CAM-001).');
        // Aunque sea de otro producto: el número de serie es único en la empresa
        $this->comprar('001-001-000000003', 1, ['SN-1'], 'NVR-001')->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'Estas series ya existen en el sistema: SN-1 (producto CAM-001).');

        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(2, ProductSerie::count());
        $this->assertSame(1, Purchase::count());
        $this->assertSame(0, ProductSerie::where('product_id', $otra->id)->count());
    }

    public function test_la_misma_serie_en_otra_empresa_no_estorba(): void
    {
        $otraEmpresa = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $otroProducto = Product::create(['company_id' => $otraEmpresa->id, 'codigo' => 'CAM-001', 'descripcion' => 'Cámara', 'tipo' => 'bien', 'maneja_series' => true]);
        ProductSerie::create(['company_id' => $otraEmpresa->id, 'product_id' => $otroProducto->id, 'serie' => 'SN-1', 'estado' => 'disponible']);

        $this->comprar('001-001-000000001', 1, ['SN-1'])->assertCreated();
        $this->assertSame(2, ProductSerie::where('serie', 'SN-1')->count());
    }

    public function test_un_producto_sin_series_no_acepta_series_y_uno_con_series_exige_unidades_enteras(): void
    {
        $this->postJson('/api/purchases', $this->cuerpo('001-001-000000001', [[
            'codigo_principal' => 'MOU-001', 'descripcion' => 'Mouse óptico', 'cantidad' => 2, 'precio_unitario' => 5, 'tarifa' => 15, 'series' => ['M-1', 'M-2'],
        ]]))->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto MOU-001 no maneja series: quita las series de esa línea o activa «Aplica Serie» en el artículo.');

        $this->comprar('001-001-000000002', 2.5, ['SN-1', 'SN-2'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto CAM-001 maneja series: la cantidad debe ser un número entero de unidades.');
        $this->sinRastro();
    }

    public function test_una_compra_con_productos_con_y_sin_series_funciona_y_los_sin_series_no_cambian(): void
    {
        $this->postJson('/api/purchases', $this->cuerpo('001-001-000000001', [
            ['codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara', 'cantidad' => 2, 'precio_unitario' => 100, 'tarifa' => 15, 'series' => ['SN-1', 'SN-2']],
            ['codigo_principal' => 'MOU-001', 'descripcion' => 'Mouse óptico', 'cantidad' => 10, 'precio_unitario' => 5, 'tarifa' => 15],
            ['codigo_principal' => 'NUEVO-1', 'descripcion' => 'Producto nuevo', 'cantidad' => 4, 'precio_unitario' => 3, 'tarifa' => 15],
        ]))->assertCreated();

        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(10.0, (float) $this->mouse->fresh()->stock);
        $this->assertSame(4.0, (float) Product::where('codigo', 'NUEVO-1')->value('stock'));
        $this->assertSame(2, ProductSerie::count());
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_verificar_series_existentes_dice_cuales_ya_estan_registradas(): void
    {
        $this->comprar('001-001-000000001', 2, ['SN-1', 'SN-2'])->assertCreated();
        $this->facturar(['SN-2']);

        $r = $this->postJson('/api/series/existentes', ['company_id' => $this->company->id, 'series' => ['SN-1', 'SN-2', 'SN-3', ' SN-1 ']])->assertOk();

        $this->assertEquals([
            ['serie' => 'SN-1', 'producto' => 'CAM-001', 'estado' => 'disponible'],
            ['serie' => 'SN-2', 'producto' => 'CAM-001', 'estado' => 'vendida'],
        ], $r->json('existentes'));
    }

    // ------------------------------------------------------------------ S2: importación del XML

    public function test_importar_un_xml_con_un_producto_con_series_sin_series_devuelve_422_estructurado_y_no_sube_el_stock(): void
    {
        $r = $this->importar('000000101', [['CAM-001', 3, 100], ['MOU-001', 5, 8]])->assertStatus(422);

        $r->assertJsonPath('requiere_series', true);
        $this->assertSame('Esta factura trae productos con series. Ingresa el número de serie de cada unidad para poder importarla.', $r->json('message'));
        $this->assertEquals([[
            'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'product_id' => $this->camara->id, 'cantidad' => 3, 'series_recibidas' => 0,
        ]], $r->json('lineas'));

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(0.0, (float) $this->mouse->fresh()->stock, 'tampoco entra el mouse: la importación es una sola operación');
        $this->assertSame(0, ProductSerie::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_importar_el_xml_con_las_series_crea_la_compra_con_el_stock_igual_a_las_series(): void
    {
        $series = ['CAM-001' => ['SN-A', 'SN-B', 'SN-C']];

        $r = $this->importar('000000102', [['CAM-001', 3, 100], ['MOU-001', 5, 8]], $series)->assertCreated();

        $compra = Purchase::findOrFail($r->json('id'));
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(5.0, (float) $this->mouse->fresh()->stock);
        $disponibles = ProductSerie::where('product_id', $this->camara->id)->where('estado', 'disponible');
        $this->assertSame(3, $disponibles->count());
        $this->assertSame(['SN-A', 'SN-B', 'SN-C'], (clone $disponibles)->orderBy('serie')->pluck('serie')->all());
        $this->assertSame([$compra->id], (clone $disponibles)->pluck('purchase_id')->unique()->values()->all());
        $this->assertSame([$this->principal->id], (clone $disponibles)->pluck('warehouse_id')->unique()->values()->all());
        $this->assertSame(['SN-A', 'SN-B', 'SN-C'], $compra->items[0]['series']);
        $this->assertArrayNotHasKey('series', $compra->items[1]);

        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
    }

    public function test_las_series_del_xml_tambien_se_aceptan_como_texto_json(): void
    {
        $this->importar('000000103', [['CAM-001', 2, 100]], null, json_encode(['CAM-001' => ['SN-A', 'SN-B']]))->assertCreated();

        $this->assertSame(2, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
    }

    public function test_importar_con_un_numero_de_series_que_no_cuadra_vuelve_a_pedirlas(): void
    {
        $r = $this->importar('000000104', [['CAM-001', 3, 100]], ['CAM-001' => ['SN-A', 'SN-B']])->assertStatus(422);

        $r->assertJsonPath('requiere_series', true);
        $this->assertSame('Las series no coinciden con las cantidades de la factura: revisa cuántas necesita cada producto.', $r->json('message'));
        $this->assertEquals([[
            'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'product_id' => $this->camara->id, 'cantidad' => 3, 'series_recibidas' => 2,
        ]], $r->json('lineas'));
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, ProductSerie::count());
    }

    public function test_importar_con_series_repetidas_o_que_ya_existen_devuelve_422(): void
    {
        $this->comprar('001-001-000000001', 1, ['SN-A'])->assertCreated();

        $this->importar('000000105', [['CAM-001', 2, 100]], ['CAM-001' => ['SN-A', 'SN-B']])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'Estas series ya existen en el sistema: SN-A (producto CAM-001).');
        $this->importar('000000106', [['CAM-001', 2, 100]], ['CAM-001' => ['SN-B', 'SN-B']])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'Hay series repetidas en la compra: SN-B.');

        $this->assertSame(1, Purchase::count());
        $this->assertSame(1.0, (float) $this->camara->fresh()->stock);
    }

    public function test_importar_un_xml_solo_con_productos_sin_series_funciona_como_siempre(): void
    {
        $this->importar('000000107', [['MOU-001', 5, 8]])->assertCreated();

        $this->assertSame(5.0, (float) $this->mouse->fresh()->stock);
        $this->assertSame(0, ProductSerie::count());
    }

    public function test_si_el_mismo_producto_va_en_dos_lineas_las_series_se_reparten_en_orden(): void
    {
        $r = $this->importar('000000108', [['CAM-001', 2, 100], ['CAM-001', 1, 110]], ['CAM-001' => ['SN-A', 'SN-B', 'SN-C']])->assertCreated();

        $compra = Purchase::findOrFail($r->json('id'));
        $this->assertSame(['SN-A', 'SN-B'], $compra->items[0]['series']);
        $this->assertSame(['SN-C'], $compra->items[1]['series']);
        $this->assertSame(3.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(3, ProductSerie::where('estado', 'disponible')->count());
    }

    public function test_el_almacen_de_xml_por_lotes_no_sube_el_stock_de_un_producto_con_series_sin_series(): void
    {
        // Es el camino de «Importar compras del SRI» (lista de pendientes): no hay dónde pedir las series
        $xml = $this->xmlCompra('000000109', [['CAM-001', 2, 100]]);

        try {
            app(StorePurchaseFromXml::class)->handle($this->company, $xml);
            $this->fail('Debió pedir las series.');
        } catch (SeriesRequeridas $e) {
            $this->assertSame('CAM-001', $e->lineas()[0]['codigo']);
            $this->assertSame(2, $e->lineas()[0]['cantidad']);
            $this->assertStringContainsString('CAM-001', $e->getMessage());
            $this->assertStringContainsString('Importar factura (XML del SRI)', $e->getMessage());
        }
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);

        $compra = app(StorePurchaseFromXml::class)->handle($this->company, $xml, null, ['CAM-001' => ['SN-A', 'SN-B']]);
        $this->assertSame(2, ProductSerie::where('purchase_id', $compra->id)->where('estado', 'disponible')->count());
        $this->assertSame(2.0, (float) $this->camara->fresh()->stock);
    }

    // ------------------------------------------------------------------ S3: búsqueda por serie

    public function test_buscar_por_un_pedazo_del_numero_sin_importar_mayusculas_trae_el_historial(): void
    {
        $this->comprar('001-001-000000010', 3, ['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003'])->assertCreated();
        $this->facturar(['DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003']);
        $factura = Invoice::firstOrFail();
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'interna',
            'motivo' => 'Cliente se arrepintió', 'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara', 'cantidad' => 1, 'precio_unitario' => 150, 'tarifa' => 15, 'series' => ['DS-2CD2143G0-I-0003'],
            ]],
        ])->assertCreated();

        $r = $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=cd2143g0')->assertOk();

        $this->assertSame(
            ['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003'],
            collect($r->json())->pluck('serie')->all()
        );
        $disponible = $r->json('0');
        $this->assertSame('disponible', $disponible['estado']);
        $this->assertSame('CAM-001', $disponible['producto']['codigo']);
        $this->assertSame('Cámara IP DS-2CD2143G0-I', $disponible['producto']['descripcion']);
        $this->assertSame('Bodega principal', $disponible['bodega']['nombre']);
        $this->assertSame('001-001-000000010', $disponible['compra']['numero']);
        $this->assertSame('Distribuidora Hik', $disponible['compra']['proveedor']);
        $this->assertSame('2026-10-05', $disponible['compra']['fecha']);
        $this->assertNull($disponible['venta']);
        $this->assertNull($disponible['devolucion']);
        $this->assertSame(['compra'], collect($disponible['historial'])->pluck('tipo')->all());

        $vendida = $r->json('1');
        $this->assertSame('vendida', $vendida['estado']);
        $this->assertSame($factura->numero, $vendida['venta']['numero']);
        $this->assertSame('Ferretería El Clavo', $vendida['venta']['cliente']);
        $this->assertNull($vendida['devolucion']);
        $this->assertSame(['compra', 'venta'], collect($vendida['historial'])->pluck('tipo')->all());
        $this->assertSame($factura->numero, $vendida['historial'][1]['documento']);
        $this->assertSame('Ferretería El Clavo', $vendida['historial'][1]['contraparte']);

        $devuelta = $r->json('2');
        $this->assertSame('disponible', $devuelta['estado']);
        $this->assertSame('NCI-000001', $devuelta['devolucion']['numero']);
        $this->assertSame($factura->numero, $devuelta['venta']['numero'], 'la venta se sigue viendo aunque la serie volvió a la bodega');
        $this->assertSame(['compra', 'venta', 'devolucion'], collect($devuelta['historial'])->pluck('tipo')->all());
        $this->assertSame('NCI-000001', $devuelta['historial'][2]['documento']);

        // Mayúsculas, minúsculas y un pedazo del final dan lo mismo
        $this->assertCount(1, $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=I-0002')->assertOk()->json());
        $this->assertCount(1, $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=i-0002')->assertOk()->json());
    }

    public function test_una_serie_dada_de_baja_muestra_la_baja_en_su_historial(): void
    {
        $this->comprar('001-001-000000010', 2, ['BAJ-1', 'BAJ-2'])->assertCreated();
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $this->camara->id, 'warehouse_id' => $this->principal->id,
            'stock_fisico' => 1, 'motivo' => 'Rota en bodega', 'series' => ['BAJ-2'],
        ])->assertOk();

        $r = $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=baj-2')->assertOk();

        $this->assertSame('danado', $r->json('0.estado'));
        $this->assertSame(['compra', 'baja'], collect($r->json('0.historial'))->pluck('tipo')->all());
    }

    public function test_la_busqueda_pide_3_caracteres_no_usa_comodines_y_respeta_la_empresa(): void
    {
        $this->comprar('001-001-000000010', 2, ['AB_1', 'ABX1'])->assertCreated();
        $otraEmpresa = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $otro = Product::create(['company_id' => $otraEmpresa->id, 'codigo' => 'X', 'descripcion' => 'X', 'tipo' => 'bien', 'maneja_series' => true]);
        ProductSerie::create(['company_id' => $otraEmpresa->id, 'product_id' => $otro->id, 'serie' => 'AB_9', 'estado' => 'disponible']);

        $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=AB')->assertStatus(422)
            ->assertJsonPath('errors.q.0', 'Escribe al menos 3 caracteres del número de serie.');
        $this->getJson('/api/series/buscar?company_id='.$this->company->id)->assertStatus(422);

        // El guion bajo y el % se buscan tal cual, no como comodines
        $this->assertSame(['AB_1'], collect($this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=AB_')->assertOk()->json())->pluck('serie')->all());
        $this->assertSame([], $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=%25%25%25')->assertOk()->json());
        // La serie de la otra empresa no sale
        $this->assertSame([], $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=AB_9')->assertOk()->json());
    }

    public function test_productos_encuentra_al_dueno_de_una_serie(): void
    {
        $this->comprar('001-001-000000010', 2, ['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002'])->assertCreated();

        $r = $this->getJson('/api/products?company_id='.$this->company->id.'&serie=cd2143')->assertOk();

        $this->assertSame(['CAM-001'], collect($r->json())->pluck('codigo')->all());
        $this->assertSame(['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002'], collect($r->json('0.series'))->pluck('serie')->sort()->values()->all());

        $this->assertSame([], $this->getJson('/api/products?company_id='.$this->company->id.'&serie=zzzz')->assertOk()->json());
        $this->getJson('/api/products?company_id='.$this->company->id.'&serie=ab')->assertStatus(422);
        // Sin el filtro el listado trae todos y no carga las series
        $todos = $this->getJson('/api/products?company_id='.$this->company->id)->assertOk();
        $this->assertCount(2, $todos->json());
        $this->assertArrayNotHasKey('series', $todos->json('0'));
    }

    public function test_el_punto_de_venta_encuentra_la_serie_exacta_y_avisa_si_esta_en_otra_bodega(): void
    {
        $otra = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Sucursal Norte']);
        $this->comprar('001-001-000000010', 1, ['SN-P'])->assertCreated();
        $this->comprar('001-001-000000011', 1, ['SN-N'], 'CAM-001', $otra)->assertCreated();

        $r = $this->getJson('/api/series/lookup?company_id='.$this->company->id.'&serie=SN-P&warehouse_id='.$this->principal->id)->assertOk();
        $this->assertSame('SN-P', $r->json('serie'));
        $this->assertSame('CAM-001', $r->json('product.codigo'));
        $this->assertSame('Bodega principal', $r->json('bodega.nombre'));

        $fuera = $this->getJson('/api/series/lookup?company_id='.$this->company->id.'&serie=SN-N&warehouse_id='.$this->principal->id)->assertStatus(404);
        $this->assertSame('La serie SN-N no está en la bodega Bodega principal: está en la bodega Sucursal Norte.', $fuera->json('message'));
        // Sin bodega pedida sigue igual que siempre
        $this->getJson('/api/series/lookup?company_id='.$this->company->id.'&serie=SN-N')->assertOk();
    }

    // ------------------------------------------------------------------ registrar series a mano no separa el stock

    public function test_registrar_series_a_mano_no_puede_pasarse_del_stock(): void
    {
        $this->comprar('001-001-000000010', 2, ['SN-1', 'SN-2'])->assertCreated();
        // Dos unidades en stock y ya hay dos series: no caben más
        $this->postJson('/api/series', ['company_id' => $this->company->id, 'product_id' => $this->camara->id, 'series' => ['SN-3']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El producto CAM-001 tiene 2 unidades en la bodega Bodega principal y ya tiene 2 series disponibles: solo puedes registrar 0 más.');
        $this->assertSame(2, ProductSerie::count());

        // Stock sin series (producto que antes no las manejaba): se pueden registrar hasta completar
        app(\App\Services\RegisterInventoryMovement::class)->handle($this->camara, 'ingreso', 2, 100, 'Inventario inicial', '2026-01-01');
        $this->postJson('/api/series', ['company_id' => $this->company->id, 'product_id' => $this->camara->id, 'series' => ['SN-3', 'SN-4', 'SN-5']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El producto CAM-001 tiene 4 unidades en la bodega Bodega principal y ya tiene 2 series disponibles: solo puedes registrar 2 más.');
        $this->postJson('/api/series', ['company_id' => $this->company->id, 'product_id' => $this->camara->id, 'series' => ['SN-3', 'SN-4']])->assertCreated();
        $this->assertSame(4, ProductSerie::where('estado', 'disponible')->count());
        $this->assertSame($this->principal->id, ProductSerie::where('serie', 'SN-3')->value('warehouse_id'));
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    // ------------------------------------------------------------------ ayudas

    private function cuerpo(string $numero, array $items): array
    {
        return [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero,
            'fecha_emision' => '2026-10-05', 'items' => $items,
        ];
    }

    private function comprar(string $numero, float $cantidad, array $series, string $codigo = 'CAM-001', ?Warehouse $bodega = null)
    {
        $cuerpo = $this->cuerpo($numero, [[
            'codigo_principal' => $codigo, 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'cantidad' => $cantidad,
            'precio_unitario' => 100, 'tarifa' => 15, 'series' => $series,
        ]]);
        if ($bodega) {
            $cuerpo['warehouse_id'] = $bodega->id;
        }

        return $this->postJson('/api/purchases', $cuerpo);
    }

    private function facturar(array $series): void
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [[
                'codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I', 'cantidad' => count($series),
                'precio_unitario' => 150, 'tarifa' => 15, 'series' => $series,
            ]],
        ])->assertCreated();
    }

    /** Una compra rechazada no deja nada: ni compra, ni stock, ni series, ni kárdex, ni asiento. */
    private function sinRastro(): void
    {
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0.0, (float) $this->camara->fresh()->stock);
        $this->assertSame(0, ProductSerie::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, CreditNote::count());
    }

    private function importar(string $secuencial, array $lineas, ?array $series = null, ?string $seriesJson = null)
    {
        $datos = [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $this->xmlCompra($secuencial, $lineas)),
        ];
        if ($series !== null) {
            $datos['series'] = $series;
        }
        if ($seriesJson !== null) {
            $datos['series'] = $seriesJson;
        }

        return $this->post('/api/purchases/import', $datos, ['Accept' => 'application/json']);
    }

    private function xmlCompra(string $secuencial, array $lineas): string
    {
        $detalles = '';
        $base = 0;
        foreach ($lineas as [$codigo, $cant, $precio]) {
            $sub = $cant * $precio;
            $base += $sub;
            $detalles .= "<detalle><codigoPrincipal>{$codigo}</codigoPrincipal><descripcion>{$codigo}</descripcion>"
                ."<cantidad>{$cant}</cantidad><precioUnitario>{$precio}</precioUnitario>"
                ."<impuestos><impuesto><codigo>2</codigo><tarifa>15</tarifa><baseImponible>{$sub}</baseImponible><valor>".($sub * 0.15).'</valor></impuesto></impuestos></detalle>';
        }
        $iva = $base * 0.15;
        $total = $base + $iva;
        $clave = str_pad($secuencial, 49, '1', STR_PAD_LEFT);

        return "<factura><infoTributaria><ruc>1790011223001</ruc><razonSocial>Proveedor Test</razonSocial>"
            ."<estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$secuencial}</secuencial><claveAcceso>{$clave}</claveAcceso></infoTributaria>"
            ."<infoFactura><fechaEmision>05/10/2026</fechaEmision><totalSinImpuestos>{$base}</totalSinImpuestos>"
            ."<totalConImpuestos><totalImpuesto><codigo>2</codigo><valor>{$iva}</valor></totalImpuesto></totalConImpuestos>"
            ."<importeTotal>{$total}</importeTotal></infoFactura><detalles>{$detalles}</detalles></factura>";
    }
}
