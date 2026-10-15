<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.2 (P1): toma física masiva. Se abre un borrador con los productos de una bodega, se captura lo contado (lo que
 * queda en blanco NO se ajusta) y al aplicar se crean los movimientos y UN asiento por toda la toma, en una sola
 * transacción. Cada prueba revisa valores reales: movimientos, stock, líneas del asiento y que un rechazo no deje nada a medias.
 *
 * Datos base (bodega B01): A-001 Arroz 10 u a $10; B-002 Azúcar 20 u a $5; C-003 Sal 8 u a $2,50;
 * D-004 Lámpara 4 u a $30 (pasadas a B02, así que B01 no las tiene); PS-001 con series (3 u a $100); un servicio y un combo.
 */
class TomaFisicaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;
    private Warehouse $b01;
    private Warehouse $b02;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Toma Fisica SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'secuencial' => 7,
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        $this->b01 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->b02 = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Secundaria']);
        Sanctum::actingAs(User::factory()->create());

        foreach ([['A-001', 'Arroz'], ['B-002', 'Azúcar'], ['C-003', 'Sal'], ['D-004', 'Lámpara']] as [$cod, $desc]) {
            $this->producto($cod, $desc);
        }
        $this->producto('PS-001', 'Portátil', ['maneja_series' => true]);
        $this->producto('SERV-1', 'Mantenimiento', ['tipo' => 'servicio']);
        $this->producto('COMBO-1', 'Combo', ['es_combo' => true]);

        $this->comprar([['A-001', 10, 10], ['B-002', 20, 5], ['C-003', 8, 2.5], ['D-004', 4, 30]], 1);
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000002', 'fecha_emision' => '2026-01-10',
            'items' => [['codigo_principal' => 'PS-001', 'descripcion' => 'Portátil', 'cantidad' => 3, 'precio_unitario' => 100, 'tarifa' => 15, 'series' => ['SN-1', 'SN-2', 'SN-3']]],
        ])->assertCreated();
        $this->transferir('D-004', 4, $this->b01, $this->b02);
    }

    // ------------------------------------------------------------------------------------ abrir el borrador

    public function test_el_borrador_carga_los_productos_con_stock_de_la_bodega_sin_series_servicios_ni_combos(): void
    {
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();

        $r = $this->crear($this->b01, ['motivo' => 'Inventario de fin de mes'])->assertCreated();

        $r->assertJsonPath('numero', 'TF-000001')->assertJsonPath('estado', 'borrador')
            ->assertJsonPath('motivo', 'Inventario de fin de mes')->assertJsonPath('warehouse.id', $this->b01->id);
        $lineas = collect($r->json('lineas'));
        $this->assertSame(['A-001', 'B-002', 'C-003'], $lineas->pluck('codigo')->all());
        $this->assertEquals([10.0, 20.0, 8.0], $lineas->pluck('stock_sistema')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([10.0, 5.0, 2.5], $lineas->pluck('costo_promedio')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([null, null, null], $lineas->pluck('contado')->all());
        $this->assertSame(['Arroz', 'Azúcar', 'Sal'], $lineas->pluck('descripcion')->all());

        // Los productos con series no entran: se ajustan por serie en Ajuste de inventario, y la respuesta lo avisa
        $this->assertSame([['codigo' => 'PS-001', 'descripcion' => 'Portátil', 'stock' => 3.0]], $this->omitidos($r));
        $this->assertSame(0, $lineas->where('codigo', 'PS-001')->count());

        // Abrir el borrador no mueve nada: ni kárdex ni asientos
        $this->assertSame($movimientos, InventoryMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
        $c = PhysicalCount::firstOrFail();
        $this->assertSame('borrador', $c->estado);
        $this->assertSame($this->company->id, (int) $c->company_id);
        $this->assertSame($this->b01->id, (int) $c->warehouse_id);
        $this->assertSame(3, $c->lines()->count());
        $this->assertSame(now()->toDateString(), $c->fecha->toDateString());
    }

    public function test_cada_bodega_carga_solo_lo_suyo_y_el_filtro_de_busqueda_recorta_las_lineas(): void
    {
        $r = $this->crear($this->b02)->assertCreated();
        $this->assertSame(['D-004'], collect($r->json('lineas'))->pluck('codigo')->all());
        $this->assertSame([], $this->omitidos($r));                                  // en B02 no hay productos con series

        $r = $this->crear($this->b01, ['buscar' => 'sal'])->assertCreated();          // descripción "Sal"
        $this->assertSame(['C-003'], collect($r->json('lineas'))->pluck('codigo')->all());
        $this->assertSame([], $this->omitidos($r));                                  // el producto con series no coincide con el filtro

        $r = $this->crear($this->b01, ['buscar' => 'ps-0'])->assertCreated();         // código del producto con series
        $this->assertSame([], $r->json('lineas'));
        $this->assertSame(['PS-001'], collect($this->omitidos($r))->pluck('codigo')->all());
    }

    public function test_incluir_sin_stock_agrega_los_productos_de_la_empresa_que_la_bodega_no_tiene(): void
    {
        $r = $this->crear($this->b01, ['incluir_sin_stock' => true])->assertCreated();

        $lineas = collect($r->json('lineas'));
        $this->assertSame(['A-001', 'B-002', 'C-003', 'D-004'], $lineas->pluck('codigo')->all());   // sin servicio, combo ni series
        $this->assertEquals(0.0, (float) $lineas->firstWhere('codigo', 'D-004')['stock_sistema']);
    }

    public function test_la_bodega_tiene_que_ser_de_la_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajena = Warehouse::create(['company_id' => $otra->id, 'codigo' => 'X01', 'nombre' => 'Ajena', 'por_defecto' => true]);

        $this->postJson('/api/physical-counts', ['company_id' => $this->company->id, 'warehouse_id' => $ajena->id])
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(0, PhysicalCount::count());
    }

    // ------------------------------------------------------------------------------------ guardar lo contado

    public function test_guardar_en_bloque_deja_en_blanco_lo_no_contado_y_rechaza_negativos(): void
    {
        $c = $this->borrador();

        $r = $this->guardar($c, ['A-001' => 8, 'B-002' => 25.5, 'C-003' => null])->assertOk();

        $this->assertSame(['A-001' => 8.0, 'B-002' => 25.5, 'C-003' => null], $this->contados($c));
        $this->assertEquals(8.0, (float) collect($r->json('lineas'))->firstWhere('codigo', 'A-001')['contado']);
        $this->assertNull(collect($r->json('lineas'))->firstWhere('codigo', 'C-003')['contado']);
        $this->assertEquals(2, $r->json('resumen.contados'));

        // Un cero es una cantidad contada (no hay nada): distinto de dejarlo en blanco
        $this->guardar($c, ['C-003' => 0])->assertOk();
        $this->assertSame(0.0, $this->contados($c)['C-003']);
        // Y volver a blanco lo deja "no contado"
        $this->guardar($c, ['C-003' => null])->assertOk();
        $this->assertNull($this->contados($c)['C-003']);

        // Negativo: 422 y nada cambia
        $this->guardar($c, ['A-001' => -1])->assertStatus(422);
        $this->assertSame(8.0, $this->contados($c)['A-001']);
        // Un producto que no está en la toma: 422
        $this->putJson("/api/physical-counts/{$c->id}", [
            'company_id' => $this->company->id, 'lineas' => [['product_id' => $this->prod('D-004')->id, 'contado' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, PhysicalCountLine::where('product_id', $this->prod('D-004')->id)->count());
    }

    public function test_el_borrador_se_puede_consultar_listar_y_eliminar(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 8, 'B-002' => 25]);

        $detalle = $this->getJson("/api/physical-counts/{$c->id}?company_id={$this->company->id}")->assertOk();
        $detalle->assertJsonPath('numero', 'TF-000001')->assertJsonPath('warehouse.nombre', 'Principal');
        $a = collect($detalle->json('lineas'))->firstWhere('codigo', 'A-001');
        $this->assertEquals(-2.0, (float) $a['diferencia']);
        $this->assertEquals(-20.0, (float) $a['valor']);
        $this->assertEquals(10.0, (float) $a['stock_actual']);
        $this->assertFalse((bool) $a['stock_cambio']);
        $this->assertEquals(20.0, (float) $detalle->json('resumen.valor_faltantes'));
        $this->assertEquals(25.0, (float) $detalle->json('resumen.valor_sobrantes'));
        $this->assertEquals(2, $detalle->json('resumen.con_diferencia'));

        $lista = $this->getJson("/api/physical-counts?company_id={$this->company->id}")->assertOk();
        $this->assertCount(1, $lista->json());
        $fila = $lista->json(0);
        $this->assertSame('TF-000001', $fila['numero']);
        $this->assertSame('Principal', $fila['warehouse']['nombre']);
        $this->assertSame('borrador', $fila['estado']);
        $this->assertSame(3, $fila['productos']);
        $this->assertSame(2, $fila['contados']);
        $this->assertEquals(20.0, (float) $fila['valor_faltantes']);
        $this->assertEquals(25.0, (float) $fila['valor_sobrantes']);

        $this->deleteJson("/api/physical-counts/{$c->id}?company_id={$this->company->id}")->assertNoContent();
        $this->assertSame(0, PhysicalCount::count());
        $this->assertSame(0, PhysicalCountLine::count());
    }

    public function test_una_toma_de_otra_empresa_no_se_ve_ni_se_toca(): void
    {
        $c = $this->borrador();
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);

        $this->getJson("/api/physical-counts/{$c->id}?company_id={$otra->id}")->assertNotFound();
        $this->putJson("/api/physical-counts/{$c->id}", ['company_id' => $otra->id, 'lineas' => []])->assertNotFound();
        $this->postJson("/api/physical-counts/{$c->id}/aplicar", ['company_id' => $otra->id])->assertNotFound();
        $this->deleteJson("/api/physical-counts/{$c->id}?company_id={$otra->id}")->assertNotFound();
        $this->assertSame([], $this->getJson("/api/physical-counts?company_id={$otra->id}")->assertOk()->json());
        $this->assertSame(1, PhysicalCount::count());
    }

    // ------------------------------------------------------------------------------------ aplicar

    public function test_aplicar_con_faltante_y_sobrante_crea_los_movimientos_exactos_y_un_solo_asiento_cuadrado(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 8, 'B-002' => 25]);       // A: faltan 2 (a $10 = $20) · B: sobran 5 (a $5 = $25) · C: en blanco
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();
        $secuencial = (int) $this->company->fresh()->secuencial;

        $r = $this->aplicar($c)->assertOk();

        $r->assertJsonPath('ok', true)->assertJsonPath('numero', 'TF-000001')->assertJsonPath('estado', 'aplicada')
            ->assertJsonPath('movimientos', 2)->assertJsonPath('stock_cambio', false);
        $this->assertEquals(20.0, (float) $r->json('valor_faltantes'));
        $this->assertEquals(25.0, (float) $r->json('valor_sobrantes'));

        // Stock: lo contado queda como stock; lo que no se contó no se toca
        $this->assertSame(8.0, $this->stock('A-001'));
        $this->assertSame(25.0, $this->stock('B-002'));
        $this->assertSame(8.0, $this->stock('C-003'));
        $this->assertSame(8.0, $this->stockBodega('A-001', $this->b01));
        $this->assertSame(25.0, $this->stockBodega('B-002', $this->b01));
        $this->assertSame(8.0, $this->stockBodega('C-003', $this->b01));
        $this->assertSame(4.0, $this->stockBodega('D-004', $this->b02));

        // Exactamente dos movimientos nuevos, ligados a la toma
        $this->assertSame($movimientos + 2, InventoryMovement::count());
        $nuevos = InventoryMovement::where('origen_type', $c->getMorphClass())->where('origen_id', $c->id)->orderBy('id')->get();
        $this->assertCount(2, $nuevos);
        [$a, $b] = [$nuevos[0], $nuevos[1]];
        $this->assertSame([$this->prod('A-001')->id, 'egreso', 2.0, 10.0], [(int) $a->product_id, $a->tipo, (float) $a->cantidad, (float) $a->costo_unitario]);
        $this->assertSame([$this->prod('B-002')->id, 'ingreso', 5.0, 5.0], [(int) $b->product_id, $b->tipo, (float) $b->cantidad, (float) $b->costo_unitario]);
        foreach ($nuevos as $m) {
            $this->assertSame('Toma física TF-000001', $m->concepto);
            $this->assertSame($this->b01->id, (int) $m->warehouse_id);
            $this->assertSame(now()->toDateString(), substr((string) $m->fecha, 0, 10));
            $this->assertNull($m->invoice_id);
            $this->assertNull($m->purchase_id);
        }
        $this->assertEquals(8.0, (float) $a->saldo_cantidad);
        $this->assertEquals(25.0, (float) $b->saldo_cantidad);
        // Y no se movió nada de lo que no se contó
        $this->assertSame(0, InventoryMovement::where('origen_id', $c->id)->where('product_id', $this->prod('C-003')->id)->count());

        // UN asiento para toda la toma: sobrantes (Debe inventario / Haber faltantes y sobrantes) y faltantes (al revés)
        $this->assertSame($asientos + 1, JournalEntry::count());
        $asiento = JournalEntry::where('origen_type', $c->getMorphClass())->where('origen_id', $c->id)->firstOrFail();
        $this->assertSame($r->json('asiento'), $asiento->numero);
        $this->assertSame('Toma física TF-000001', $asiento->concepto);
        $this->assertSame(now()->toDateString(), $asiento->fecha->toDateString());
        $this->assertSame('pendiente', $asiento->estado);
        $this->assertEquals(45.0, (float) $asiento->total_debe);
        $this->assertEquals(45.0, (float) $asiento->total_haber);
        $lineas = $asiento->lines()->with('account')->orderBy('id')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->all();
        $this->assertEquals([['1.1.05', 25.0, 0.0], ['5.1.03', 0.0, 25.0], ['5.1.03', 20.0, 0.0], ['1.1.05', 0.0, 20.0]], $lineas);
        $this->assertEquals(45.0, round(array_sum(array_column($lineas, 1)), 2));
        $this->assertEquals(45.0, round(array_sum(array_column($lineas, 2)), 2));

        // La toma queda aplicada y no gastó el secuencial de las facturas
        $c->refresh();
        $this->assertSame('aplicada', $c->estado);
        $this->assertNotNull($c->applied_at);
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial);
        $linea = $c->lines()->where('product_id', $this->prod('A-001')->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $linea->stock_al_aplicar);
        $this->assertEquals(-2.0, (float) $linea->diferencia);
        $this->assertEquals(-20.0, (float) $linea->valor);

        // Abierta de nuevo, la toma aplicada muestra lo que pasó
        $detalle = $this->getJson("/api/physical-counts/{$c->id}?company_id={$this->company->id}")->assertOk();
        $detalle->assertJsonPath('estado', 'aplicada')->assertJsonPath('asiento', $asiento->numero);
        $this->assertEquals(-2.0, (float) collect($detalle->json('lineas'))->firstWhere('codigo', 'A-001')['diferencia']);
        $this->assertEquals(25.0, (float) $detalle->json('resumen.valor_sobrantes'));

        // Las comprobaciones del sistema siguen en verde
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_una_toma_aplicada_es_inmutable(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 8]);
        $this->aplicar($c)->assertOk();
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();

        $this->aplicar($c)->assertStatus(422)->assertJsonPath('message', 'Esta toma física ya fue aplicada: no se puede modificar ni aplicar otra vez.');
        $this->guardar($c, ['A-001' => 1])->assertStatus(422);
        $this->deleteJson("/api/physical-counts/{$c->id}?company_id={$this->company->id}")->assertStatus(422);

        $this->assertSame($movimientos, InventoryMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(8.0, $this->stock('A-001'));
        $this->assertSame(8.0, $this->contados($c)['A-001']);
        $this->assertSame(1, PhysicalCount::count());
    }

    public function test_solo_sobrantes_deja_un_asiento_de_dos_lineas(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 12, 'B-002' => 21]);        // +2 a $10 y +1 a $5

        $r = $this->aplicar($c)->assertOk();

        $this->assertEquals(0.0, (float) $r->json('valor_faltantes'));
        $this->assertEquals(25.0, (float) $r->json('valor_sobrantes'));
        $this->assertSame(['ingreso', 'ingreso'], InventoryMovement::where('origen_id', $c->id)->orderBy('id')->pluck('tipo')->all());
        $this->assertSame([12.0, 21.0, 8.0], [$this->stock('A-001'), $this->stock('B-002'), $this->stock('C-003')]);
        $this->assertEquals([['1.1.05', 25.0, 0.0], ['5.1.03', 0.0, 25.0]], $this->lineasDelAsiento($c));
    }

    public function test_solo_faltantes_deja_un_asiento_de_dos_lineas(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 7, 'C-003' => 0]);          // -3 a $10 y -8 a $2,50

        $r = $this->aplicar($c)->assertOk();

        $this->assertEquals(50.0, (float) $r->json('valor_faltantes'));
        $this->assertEquals(0.0, (float) $r->json('valor_sobrantes'));
        $this->assertSame(['egreso', 'egreso'], InventoryMovement::where('origen_id', $c->id)->orderBy('id')->pluck('tipo')->all());
        $this->assertSame([7.0, 20.0, 0.0], [$this->stock('A-001'), $this->stock('B-002'), $this->stock('C-003')]);
        $this->assertEquals([['5.1.03', 50.0, 0.0], ['1.1.05', 0.0, 50.0]], $this->lineasDelAsiento($c));
    }

    public function test_sin_diferencias_responde_422_y_no_crea_nada(): void
    {
        $c = $this->borrador();
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();

        // Nada contado
        $this->aplicar($c)->assertStatus(422)->assertJsonPath('message', 'No hay diferencias que ajustar.');
        // Contado igual al stock
        $this->guardar($c, ['A-001' => 10, 'B-002' => 20]);
        $this->aplicar($c)->assertStatus(422)->assertJsonPath('message', 'No hay diferencias que ajustar.');

        $this->assertSame($movimientos, InventoryMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame('borrador', $c->fresh()->estado);
        $this->assertNull($c->fresh()->applied_at);
    }

    public function test_si_el_periodo_de_hoy_esta_cerrado_responde_422_y_no_cambia_nada(): void
    {
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 8, 'B-002' => 25]);
        $antes = $this->foto();
        $periodo = AccountingPeriod::create([
            'company_id' => $this->company->id, 'anio' => (int) now()->year, 'mes' => (int) now()->month, 'estado' => AccountingPeriod::CERRADO,
        ]);

        $r = $this->aplicar($c)->assertStatus(422);

        $this->assertStringContainsString('está cerrado', (string) ($r->json('errors.fecha.0') ?? $r->json('message')));
        $this->assertSame($antes, $this->foto());                  // ni kárdex, ni stock, ni asientos
        $this->assertSame('borrador', $c->fresh()->estado);
        $this->assertSame(8.0, $this->contados($c)['A-001']);       // lo capturado sigue ahí

        // Reabierto el mes, la misma toma se aplica sin problema
        $periodo->update(['estado' => AccountingPeriod::ABIERTO]);
        $this->aplicar($c)->assertOk();
        $this->assertSame(8.0, $this->stock('A-001'));
    }

    public function test_si_el_stock_cambio_desde_el_borrador_gana_el_stock_actual_y_la_respuesta_lo_avisa(): void
    {
        $c = $this->borrador();                                    // A: 10 en el borrador
        $this->guardar($c, ['A-001' => 8, 'B-002' => 20]);
        $this->transferir('A-001', 3, $this->b01, $this->b02);     // alguien movió 3 mientras se contaba: B01 ahora tiene 7

        $detalle = $this->getJson("/api/physical-counts/{$c->id}?company_id={$this->company->id}")->assertOk();
        $a = collect($detalle->json('lineas'))->firstWhere('codigo', 'A-001');
        $this->assertEquals(10.0, (float) $a['stock_sistema']);
        $this->assertEquals(7.0, (float) $a['stock_actual']);
        $this->assertTrue((bool) $a['stock_cambio']);

        $r = $this->aplicar($c)->assertOk();

        // 8 contados contra 7 actuales = sobran 1 (no faltan 2); B no cambió y no tiene diferencia
        $r->assertJsonPath('stock_cambio', true)->assertJsonPath('movimientos', 1);
        $this->assertSame([['codigo' => 'A-001', 'stock_sistema' => 10.0, 'stock_actual' => 7.0]], array_map(
            fn ($x) => ['codigo' => $x['codigo'], 'stock_sistema' => (float) $x['stock_sistema'], 'stock_actual' => (float) $x['stock_actual']],
            $r->json('productos_con_cambio')
        ));
        $m = InventoryMovement::where('origen_id', $c->id)->firstOrFail();
        $this->assertSame(['ingreso', 1.0], [$m->tipo, (float) $m->cantidad]);
        $this->assertSame(8.0, $this->stockBodega('A-001', $this->b01));
        $this->assertSame(3.0, $this->stockBodega('A-001', $this->b02));
    }

    public function test_un_ingreso_por_toma_se_valora_al_costo_promedio_vigente(): void
    {
        $this->comprar([['A-001', 10, 20]], 5);                     // el promedio de A pasa a (100 + 200) / 20 = $15
        $c = $this->borrador();
        $this->guardar($c, ['A-001' => 22]);                        // sobran 2 sobre 20

        $this->aplicar($c)->assertOk();

        $m = InventoryMovement::where('origen_id', $c->id)->firstOrFail();
        $this->assertEquals(15.0, (float) $m->costo_unitario);
        $this->assertEquals([['1.1.05', 30.0, 0.0], ['5.1.03', 0.0, 30.0]], $this->lineasDelAsiento($c));
    }

    // ------------------------------------------------------------------------------------ numeración

    public function test_la_numeracion_es_propia_de_la_empresa_y_no_toca_el_secuencial(): void
    {
        $secuencial = (int) $this->company->fresh()->secuencial;

        $this->assertSame('TF-000001', $this->crear($this->b01)->assertCreated()->json('numero'));
        $this->assertSame('TF-000002', $this->crear($this->b02)->assertCreated()->json('numero'));
        $this->assertSame('TF-000003', $this->crear($this->b01)->assertCreated()->json('numero'));
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial);

        // Otra empresa arranca en TF-000001
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'secuencial' => 99]);
        $bodegaOtra = Warehouse::create(['company_id' => $otra->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->postJson('/api/physical-counts', ['company_id' => $otra->id, 'warehouse_id' => $bodegaOtra->id])
            ->assertCreated()->assertJsonPath('numero', 'TF-000001');
        $this->assertSame(99, (int) $otra->fresh()->secuencial);

        // El número que queda libre al borrar el último se vuelve a usar, pero nunca uno ya aplicado
        $ultimo = PhysicalCount::where('company_id', $this->company->id)->where('numero', 'TF-000003')->firstOrFail();
        $this->deleteJson("/api/physical-counts/{$ultimo->id}?company_id={$this->company->id}")->assertNoContent();
        $this->assertSame('TF-000003', $this->crear($this->b01)->assertCreated()->json('numero'));
    }

    // ------------------------------------------------------------------------------------ hoja de conteo

    public function test_la_hoja_de_conteo_sale_en_excel_y_pdf_y_se_puede_pedir_a_ciegas(): void
    {
        $c = $this->borrador();

        $csv = $this->get("/api/physical-counts/{$c->id}/hoja?company_id={$this->company->id}&formato=excel")->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $texto = $csv->getContent();
        $this->assertStringContainsString('"Código","Producto","Stock del sistema","Contado"', $texto);
        $this->assertStringContainsString('"A-001","Arroz","10.00",""', $texto);
        $this->assertStringContainsString('"C-003","Sal","8.00",""', $texto);

        $ciego = $this->get("/api/physical-counts/{$c->id}/hoja?company_id={$this->company->id}&formato=excel&ciego=1")->assertOk()->getContent();
        $this->assertStringNotContainsString('Stock del sistema', $ciego);
        $this->assertStringContainsString('"A-001","Arroz",""', $ciego);

        $pdf = $this->get("/api/physical-counts/{$c->id}/hoja?company_id={$this->company->id}&formato=pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    // ------------------------------------------------------------------------------------ ayudantes

    private function producto(string $codigo, string $descripcion, array $extra = []): Product
    {
        return Product::create($extra + ['company_id' => $this->company->id, 'codigo' => $codigo, 'descripcion' => $descripcion, 'tipo' => 'bien']);
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
            'numero' => '001-001-'.str_pad((string) (100 + $n), 9, '0', STR_PAD_LEFT), 'fecha_emision' => '2026-01-10',
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();
    }

    private function transferir(string $codigo, float $cantidad, Warehouse $origen, Warehouse $destino): void
    {
        $this->postJson('/api/inventory/transferencia', [
            'company_id' => $this->company->id, 'product_id' => $this->prod($codigo)->id,
            'warehouse_origen_id' => $origen->id, 'warehouse_destino_id' => $destino->id, 'cantidad' => $cantidad, 'motivo' => 'Prueba',
        ])->assertOk();
    }

    private function crear(Warehouse $bodega, array $extra = [])
    {
        return $this->postJson('/api/physical-counts', ['company_id' => $bodega->company_id, 'warehouse_id' => $bodega->id] + $extra);
    }

    private function borrador(?Warehouse $bodega = null): PhysicalCount
    {
        $id = $this->crear($bodega ?? $this->b01)->assertCreated()->json('id');

        return PhysicalCount::findOrFail($id);
    }

    /** Guarda lo contado: ['A-001' => 8, 'C-003' => null]. */
    private function guardar(PhysicalCount $c, array $contado)
    {
        return $this->putJson("/api/physical-counts/{$c->id}", [
            'company_id' => $c->company_id,
            'lineas' => collect($contado)->map(fn ($v, $cod) => ['product_id' => $this->prod($cod)->id, 'contado' => $v])->values()->all(),
        ]);
    }

    private function aplicar(PhysicalCount $c)
    {
        return $this->postJson("/api/physical-counts/{$c->id}/aplicar", ['company_id' => $c->company_id]);
    }

    /** ['A-001' => 8.0, 'C-003' => null] tal como quedó en la base. */
    private function contados(PhysicalCount $c): array
    {
        return $c->lines()->with('product:id,codigo')->get()
            ->mapWithKeys(fn ($l) => [$l->product->codigo => $l->contado === null ? null : (float) $l->contado])->sortKeys()->all();
    }

    private function omitidos($respuesta): array
    {
        return collect($respuesta->json('omitidos_series'))
            ->map(fn ($x) => ['codigo' => $x['codigo'], 'descripcion' => $x['descripcion'], 'stock' => (float) $x['stock']])->values()->all();
    }

    private function lineasDelAsiento(PhysicalCount $c): array
    {
        $asiento = JournalEntry::where('origen_type', $c->getMorphClass())->where('origen_id', $c->id)->firstOrFail();

        return $asiento->lines()->with('account')->orderBy('id')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->all();
    }

    /** Todo lo que un rechazo no debe tocar. */
    private function foto(): array
    {
        return [
            'movimientos' => InventoryMovement::count(),
            'asientos' => JournalEntry::count(),
            'lineas_asiento' => \App\Models\JournalEntryLine::count(),
            'stock' => Product::orderBy('id')->pluck('stock', 'codigo')->map(fn ($s) => (float) $s)->all(),
            'bodegas' => WarehouseStock::orderBy('id')->get(['warehouse_id', 'product_id', 'stock'])->toArray(),
            'secuencial' => (int) $this->company->fresh()->secuencial,
        ];
    }
}
