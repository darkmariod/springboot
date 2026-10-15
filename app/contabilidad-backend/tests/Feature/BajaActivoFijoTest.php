<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;

/**
 * T7.2 · A4: dar de baja un activo. Primero se deprecia lo pendiente hasta el mes de la baja; luego un asiento saca el costo y toda
 * la depreciación acumulada, registra lo cobrado si se vendió y deja la pérdida o la utilidad. Cada prueba revisa las líneas.
 *
 * El activo base cuesta $1200 a 12 meses (100 al mes) y tiene enero, febrero y marzo depreciados: acumulado $300. Se da de baja
 * el 2026-04-10, así que abril (100) se deprecia al darlo de baja: acumulado $400 y valor en libros $800.
 */
class BajaActivoFijoTest extends ActivosFijosTestCase
{
    private function activoDepreciado(): FixedAsset
    {
        $a = $this->activo();
        $this->rango('2026-01', '2026-03')->assertCreated();

        return $a;
    }

    public function test_baja_sin_venta_deprecia_lo_pendiente_y_manda_el_valor_en_libros_a_perdida(): void
    {
        $a = $this->activoDepreciado();

        $r = $this->baja($a, ['motivo' => 'Obsoleto'])->assertOk();

        // Abril se depreció al darlo de baja, con la fecha de la baja
        $abril = JournalEntry::where('concepto', 'like', 'Depreciación%')->orderByDesc('id')->firstOrFail();
        $this->assertLineas($abril, [['5.4.01', 100, 0], ['1.2.02', 0, 100]]);
        $this->assertSame('2026-04-10', $abril->fecha->toDateString());
        $this->assertSame(4, FixedAssetDepreciation::where('fixed_asset_id', $a->id)->count());

        // Debe depreciación acumulada (400) + pérdida (800) / Haber activos fijos (1200)
        $baja = JournalEntry::findOrFail($r->json('asiento.id'));
        $this->assertLineas($baja, [['1.2.02', 400, 0], ['5.4.02', 800, 0], ['1.2.01', 0, 1200]]);
        $this->assertSame('2026-04-10', $baja->fecha->toDateString());
        $this->assertSame('pendiente', $baja->estado);
        $this->assertSame($a->getMorphClass(), $baja->origen_type);
        $this->assertSame($a->id, (int) $baja->origen_id);

        $a->refresh();
        $this->assertSame('dado_de_baja', $a->estado);
        $this->assertSame('2026-04-10', $a->fecha_baja->toDateString());
        $this->assertSame('Obsoleto', $a->motivo_baja);
        $this->assertSame($baja->id, (int) $a->journal_entry_baja_id);
        $r->assertJsonPath('activo.estado', 'dado_de_baja')->assertJsonPath('depreciaciones.meses', 1);
        $this->assertEquals(100, $r->json('depreciaciones.total'));
        $this->assertEquals(-800, $r->json('resultado'));

        // Quedó todo en cero en el mayor: ni costo ni depreciación acumulada; el gasto total es la depreciación más la pérdida
        $this->assertEquals(0, $this->saldo('activos_fijos'));
        $this->assertEquals(0, $this->saldo('depreciacion_acumulada', -1));
        $this->assertEquals(400, $this->saldo('gasto_depreciacion'));
        $this->assertEquals(800, $this->saldo('perdida_baja_activos'));

        $this->chequeoEstricto()->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_baja_con_venta_por_encima_del_valor_en_libros_deja_utilidad(): void
    {
        $a = $this->activoDepreciado();

        $r = $this->baja($a, ['valor_venta' => 1000, 'cuenta_cobro' => 'bancos'])->assertOk();

        // Se cobra 1000 por algo que vale 800 en libros: utilidad de 200
        $baja = JournalEntry::findOrFail($r->json('asiento.id'));
        $this->assertLineas($baja, [['1.2.02', 400, 0], ['1.1.02', 1000, 0], ['4.1.04', 0, 200], ['1.2.01', 0, 1200]]);
        $this->assertEquals(200, $r->json('resultado'));
        $this->assertEquals(1000, $a->fresh()->valor_venta);
        $this->assertEquals(1000, $this->saldo('bancos'));
        $this->assertEquals(200, $this->saldo('utilidad_baja_activos', -1));
        $this->assertEquals(0, $this->saldo('activos_fijos'));
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_baja_con_venta_por_debajo_del_valor_en_libros_en_caja_deja_perdida(): void
    {
        $a = $this->activoDepreciado();

        $r = $this->baja($a, ['valor_venta' => 500, 'cuenta_cobro' => 'caja'])->assertOk();

        $baja = JournalEntry::findOrFail($r->json('asiento.id'));
        $this->assertLineas($baja, [['1.2.02', 400, 0], ['1.1.01', 500, 0], ['5.4.02', 300, 0], ['1.2.01', 0, 1200]]);
        $this->assertEquals(-300, $r->json('resultado'));
        $this->assertEquals(500, $this->saldo('caja'));
        $this->assertEquals(300, $this->saldo('perdida_baja_activos'));
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_venta_igual_al_valor_en_libros_no_deja_ni_perdida_ni_utilidad(): void
    {
        $a = $this->activoDepreciado();

        $r = $this->baja($a, ['valor_venta' => 800])->assertOk();   // cuenta de cobro por defecto: Bancos

        $baja = JournalEntry::findOrFail($r->json('asiento.id'));
        $this->assertLineas($baja, [['1.2.02', 400, 0], ['1.1.02', 800, 0], ['1.2.01', 0, 1200]]);
        $this->assertEquals(0, $r->json('resultado'));
    }

    public function test_un_activo_sin_depreciacion_se_deprecia_el_mes_de_la_baja_y_se_da_de_baja(): void
    {
        $a = $this->activo();   // empieza en enero y nunca se depreció

        $r = $this->baja($a, ['fecha' => '2026-01-20'])->assertOk();

        $this->assertSame(1, FixedAssetDepreciation::where('fixed_asset_id', $a->id)->count());
        $this->assertLineas(JournalEntry::findOrFail($r->json('asiento.id')), [['1.2.02', 100, 0], ['5.4.02', 1100, 0], ['1.2.01', 0, 1200]]);

        // Un activo que ni empezó a depreciarse: no hay nada que depreciar
        $b = $this->activo(['nombre' => 'Nuevo', 'fecha_adquisicion' => '2026-09-01', 'fecha_inicio_depreciacion' => '2026-10-01']);
        $r = $this->baja($b, ['fecha' => '2026-09-15'])->assertOk();
        $this->assertSame(0, FixedAssetDepreciation::where('fixed_asset_id', $b->id)->count());
        $this->assertLineas(JournalEntry::findOrFail($r->json('asiento.id')), [['5.4.02', 1200, 0], ['1.2.01', 0, 1200]]);
    }

    public function test_no_se_da_de_baja_dos_veces_y_despues_ya_no_se_deprecia(): void
    {
        $a = $this->activoDepreciado();
        $this->baja($a)->assertOk();
        $asientos = JournalEntry::count();

        $this->baja($a)->assertStatus(422)->assertJsonValidationErrors(['activo']);
        $this->assertSame($asientos, JournalEntry::count());

        $r = $this->mes('2026-06')->assertOk();
        $this->assertSame(0, $r->json('depreciados'));
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(4, FixedAssetDepreciation::count());
    }

    public function test_la_baja_en_un_periodo_cerrado_da_422_y_no_cambia_nada(): void
    {
        $a = $this->activoDepreciado();
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 4, 'estado' => 'cerrado']);
        $asientos = JournalEntry::count();

        $this->baja($a)->assertStatus(422)->assertJsonValidationErrors(['fecha']);

        $this->assertSame('activo', $a->fresh()->estado);
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(3, FixedAssetDepreciation::count());   // ni la depreciación de abril quedó
        $this->assertEquals(1200, $this->saldo('activos_fijos'));
    }

    public function test_valida_la_fecha_el_motivo_y_la_venta(): void
    {
        $a = $this->activoDepreciado();

        $this->baja($a, ['fecha' => '2026-01-05'])->assertStatus(422)->assertJsonValidationErrors(['fecha']);   // antes de adquirirlo
        $this->baja($a, ['fecha' => '2026-10-20'])->assertStatus(422)->assertJsonValidationErrors(['fecha']);   // futura
        $this->baja($a, ['motivo' => ''])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->baja($a, ['valor_venta' => -5])->assertStatus(422)->assertJsonValidationErrors(['valor_venta']);
        // Las cuentas por cobrar solo se mueven desde una factura: la venta se cobra en bancos o en caja
        $this->baja($a, ['valor_venta' => 100, 'cuenta_cobro' => 'cxc'])->assertStatus(422)->assertJsonValidationErrors(['cuenta_cobro']);

        $this->assertSame('activo', $a->fresh()->estado);
        $this->assertSame(3, FixedAssetDepreciation::count());
    }

    public function test_un_activo_dado_de_baja_conserva_su_historial_y_no_se_edita_en_dinero(): void
    {
        $a = $this->activoDepreciado();
        $this->baja($a)->assertOk();

        $ficha = collect($this->getJson("/api/fixed-assets?company_id={$this->company->id}&estado=dado_de_baja")->assertOk()->json('activos'))->sole();
        $this->assertEquals(0, $ficha['valor_en_libros']);
        $this->assertEquals(400, $ficha['depreciacion_acumulada']);
        $this->assertSame(0, $ficha['meses_restantes']);
        $this->assertNull($ficha['proximo_mes']);

        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['costo' => 1500]))->assertStatus(422)->assertJsonValidationErrors(['costo']);
        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['observacion' => 'Entregado a reciclaje']))->assertOk();
    }
}
