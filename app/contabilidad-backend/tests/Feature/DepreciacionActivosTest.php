<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * T7.2 · A3: depreciación mensual automática en línea recta. Cada prueba revisa montos y líneas reales del asiento:
 * Debe Gasto por depreciación (5.4.01) / Haber Depreciación acumulada (1.2.02), UN asiento por mes para todos los activos.
 */
class DepreciacionActivosTest extends ActivosFijosTestCase
{
    /** Asientos de depreciación (no el alta ni la baja), del más viejo al más nuevo. */
    private function depreciaciones()
    {
        return JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Depreciación%')->orderBy('id')->get();
    }

    // ------------------------------------------------------------- la cuota

    public function test_la_cuota_mensual_en_linea_recta_y_el_asiento_del_mes(): void
    {
        $a = $this->activo();   // $1200 / 12 meses, desde enero

        $r = $this->mes('2026-01')->assertCreated();
        $r->assertJsonPath('simulado', false)->assertJsonPath('depreciados', 1)->assertJsonPath('meses.0.periodo', '2026-01')
            ->assertJsonPath('meses.0.fecha', '2026-01-31')->assertJsonPath('meses.0.activos.0.codigo', $a->codigo);
        $this->assertEquals(100, $r->json('total'));
        $this->assertEquals(100, $r->json('meses.0.activos.0.monto'));
        $this->assertEquals(100, $r->json('meses.0.activos.0.acumulada_despues'));
        $this->assertEquals(1100, $r->json('meses.0.activos.0.valor_en_libros_despues'));

        $e = $this->depreciaciones()->sole();
        $this->assertLineas($e, [['5.4.01', 100, 0], ['1.2.02', 0, 100]]);
        $this->assertSame('2026-01-31', $e->fecha->toDateString());
        $this->assertSame('pendiente', $e->estado);
        $this->assertSame($e->id, $r->json('meses.0.asiento.id'));
        $this->assertSame($e->numero, $r->json('meses.0.asiento.numero'));

        // La fila del cronograma sabe a qué asiento pertenece, y el asiento apunta a esa fila como origen
        $fila = FixedAssetDepreciation::sole();
        $this->assertEquals(100, $fila->monto);
        $this->assertEquals(100, $fila->depreciacion_acumulada_despues);
        $this->assertSame([2026, 1], [(int) $fila->anio, (int) $fila->mes]);
        $this->assertSame($e->id, (int) $fila->journal_entry_id);
        $this->assertSame($a->id, (int) $fila->fixed_asset_id);
        $this->assertSame($fila->getMorphClass(), $e->origen_type);
        $this->assertSame($fila->id, (int) $e->origen_id);

        // En el mayor: gasto 100 contra depreciación acumulada 100, y el activo sigue en su costo
        $this->assertEquals(100, $this->saldo('gasto_depreciacion'));
        $this->assertEquals(100, $this->saldo('depreciacion_acumulada', -1));
        $this->assertEquals(1200, $this->saldo('activos_fijos'));
    }

    public function test_con_valor_residual_la_cuota_es_costo_menos_residual_entre_la_vida_util(): void
    {
        $this->activo(['costo' => 1000, 'valor_residual' => 100, 'vida_util_meses' => 36]);

        $r = $this->mes('2026-01')->assertCreated();

        $this->assertEquals(25, $r->json('total'));   // (1000 − 100) / 36
        $this->assertLineas($this->depreciaciones()->sole(), [['5.4.01', 25, 0], ['1.2.02', 0, 25]]);
    }

    public function test_el_ultimo_mes_absorbe_los_centavos_y_el_total_nunca_pasa_de_lo_depreciable(): void
    {
        $a = $this->activo(['costo' => 1000, 'valor_residual' => 0, 'vida_util_meses' => 3, 'fecha_adquisicion' => '2026-05-02', 'fecha_inicio_depreciacion' => '2026-05-01']);

        $r = $this->rango('2026-05', '2026-07')->assertCreated();

        $this->assertSame(3, $r->json('depreciados'));
        $this->assertEquals([333.33, 333.33, 333.34], FixedAssetDepreciation::orderBy('anio')->orderBy('mes')->pluck('monto')->map(fn ($m) => (float) $m)->all());
        $this->assertEquals([333.33, 666.66, 1000.00], FixedAssetDepreciation::orderBy('anio')->orderBy('mes')->pluck('depreciacion_acumulada_despues')->map(fn ($m) => (float) $m)->all());
        $this->assertEquals(1000, round((float) FixedAssetDepreciation::sum('monto'), 2));
        $this->assertEquals(1000, $this->saldo('depreciacion_acumulada', -1));

        // Agosto ya no tiene nada que depreciar: el activo llegó a su valor residual
        $r = $this->mes('2026-08')->assertOk();
        $this->assertSame(0, $r->json('depreciados'));
        $this->assertSame(3, FixedAssetDepreciation::count());
        $this->assertEquals(0, collect($this->getJson("/api/fixed-assets?company_id={$this->company->id}")->json('activos'))->firstWhere('id', $a->id)['valor_en_libros']);
    }

    public function test_la_cuota_redondeada_hacia_arriba_tampoco_pasa_del_total(): void
    {
        // 100 / 6 = 16.666…: cinco cuotas de 16.67 suman 83.35 y la sexta cierra en 16.65
        $this->activo(['costo' => 100, 'vida_util_meses' => 6, 'fecha_adquisicion' => '2026-01-02']);

        $this->rango('2026-01', '2026-06')->assertCreated();

        $montos = FixedAssetDepreciation::orderBy('mes')->pluck('monto')->map(fn ($m) => (float) $m)->all();
        $this->assertEquals([16.67, 16.67, 16.67, 16.67, 16.67, 16.65], $montos);
        $this->assertEquals(100, round(array_sum($montos), 2));
    }

    // ------------------------------------------------------------- un asiento por mes

    public function test_un_solo_asiento_por_mes_para_todos_los_activos(): void
    {
        $a = $this->activo();                                              // 100 al mes
        $b = $this->activo(['nombre' => 'Escritorio', 'costo' => 600]);    // 50 al mes
        $c = $this->activo(['nombre' => 'Sin empezar', 'fecha_inicio_depreciacion' => '2026-03-01']);

        $r = $this->mes('2026-01')->assertCreated();

        $this->assertSame(2, $r->json('depreciados'));
        $this->assertEquals(150, $r->json('total'));
        $e = $this->depreciaciones()->sole();
        $this->assertLineas($e, [['5.4.01', 150, 0], ['1.2.02', 0, 150]]);
        $filas = FixedAssetDepreciation::where('journal_entry_id', $e->id)->get();
        $this->assertSame([$a->id, $b->id], $filas->pluck('fixed_asset_id')->map(fn ($i) => (int) $i)->sort()->values()->all());
        $this->assertSame(0, FixedAssetDepreciation::where('fixed_asset_id', $c->id)->count());
    }

    // ------------------------------------------------------------- idempotencia

    public function test_depreciar_dos_veces_el_mismo_mes_no_duplica_nada(): void
    {
        $this->activo();
        $this->mes('2026-01')->assertCreated();
        $asientos = JournalEntry::count();

        $r = $this->mes('2026-01')->assertOk();

        $this->assertSame(0, $r->json('depreciados'));
        $this->assertEquals(0, $r->json('total'));
        $this->assertSame(1, FixedAssetDepreciation::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(100, $this->saldo('depreciacion_acumulada', -1));
        $this->assertSame('ya_depreciado', $r->json('omitidos.0.motivo'));
        $this->assertStringContainsString('Ya está depreciado', $r->json('mensaje'));
    }

    public function test_un_activo_depreciado_en_un_mes_no_vuelve_a_entrar_pero_si_uno_nuevo(): void
    {
        $this->activo();
        $this->mes('2026-01')->assertCreated();
        $this->activo(['nombre' => 'Escritorio', 'costo' => 600]);

        $r = $this->mes('2026-01')->assertCreated();

        $this->assertSame(1, $r->json('depreciados'));
        $this->assertEquals(50, $r->json('total'));
        $this->assertSame(2, FixedAssetDepreciation::count());
        $this->assertCount(2, $this->depreciaciones());   // un asiento por llamada: el segundo solo lleva lo nuevo
        $this->assertEquals(150, $this->saldo('depreciacion_acumulada', -1));
    }

    public function test_un_activo_que_aun_no_empieza_no_se_deprecia(): void
    {
        $this->activo(['fecha_inicio_depreciacion' => '2026-03-01']);

        $r = $this->mes('2026-02')->assertOk();

        $this->assertSame(0, $r->json('depreciados'));
        $this->assertCount(0, $this->depreciaciones());
    }

    // ------------------------------------------------------------- meses futuros

    public function test_no_deja_depreciar_un_mes_futuro_pero_si_el_mes_en_curso(): void
    {
        $this->activo(['fecha_adquisicion' => '2026-10-01', 'fecha_inicio_depreciacion' => '2026-10-01']);

        $this->mes('2026-11')->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->rango('2026-10', '2027-01')->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->assertSame(0, FixedAssetDepreciation::count());

        $r = $this->mes('2026-10')->assertCreated();   // hoy es 14 de octubre de 2026
        $this->assertSame('2026-10-31', $r->json('meses.0.fecha'));
        $this->assertSame('2026-10-31', $this->depreciaciones()->sole()->fecha->toDateString());
    }

    public function test_valida_el_mes_y_el_rango(): void
    {
        $this->activo();
        $this->depreciar([])->assertStatus(422);
        $this->depreciar(['anio' => 2026, 'mes' => 13])->assertStatus(422)->assertJsonValidationErrors(['mes']);
        $this->rango('2026-06', '2026-03')->assertStatus(422)->assertJsonValidationErrors(['desde']);
        $this->rango('2020-01', '2026-10')->assertStatus(422)->assertJsonValidationErrors(['desde']);   // más de 60 meses de una vez
        $this->assertSame(0, FixedAssetDepreciation::count());
    }

    // ------------------------------------------------------------- recuperar meses atrasados

    public function test_el_rango_deprecia_cada_mes_en_orden_con_su_propio_asiento(): void
    {
        $a = $this->activo(['fecha_adquisicion' => '2026-03-05', 'fecha_inicio_depreciacion' => '2026-03-01']);

        $r = $this->rango('2026-03', '2026-06')->assertCreated();

        $this->assertSame(4, $r->json('depreciados'));
        $this->assertEquals(400, $r->json('total'));
        $this->assertSame(['2026-03', '2026-04', '2026-05', '2026-06'], collect($r->json('meses'))->pluck('periodo')->all());
        $asientos = $this->depreciaciones();
        $this->assertCount(4, $asientos);
        $this->assertSame(['2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $asientos->map(fn ($e) => $e->fecha->toDateString())->all());
        // Números consecutivos en el orden de los meses
        $numeros = $asientos->pluck('numero')->all();
        $ordenados = $numeros;
        sort($ordenados);
        $this->assertSame($ordenados, $numeros);
        $this->assertEquals([100, 200, 300, 400], FixedAssetDepreciation::where('fixed_asset_id', $a->id)->orderBy('mes')->pluck('depreciacion_acumulada_despues')->map(fn ($m) => (float) $m)->all());
        foreach ($asientos as $e) {
            $this->assertLineas($e, [['5.4.01', 100, 0], ['1.2.02', 0, 100]]);
        }
    }

    public function test_un_mes_suelto_no_se_salta_los_meses_anteriores_sin_depreciar(): void
    {
        $this->activo(['fecha_adquisicion' => '2026-03-05', 'fecha_inicio_depreciacion' => '2026-03-01']);

        $r = $this->mes('2026-06')->assertOk();

        $this->assertSame(0, $r->json('depreciados'));
        $this->assertSame('pendiente_anterior', $r->json('omitidos.0.motivo'));
        $this->assertSame('2026-03', $r->json('omitidos.0.pendiente_desde'));
        $this->assertSame('2026-03', $r->json('pendiente_desde'));
        $this->assertSame(0, FixedAssetDepreciation::count());
        $this->assertCount(0, $this->depreciaciones());

        // Con el rango desde el primer mes pendiente sí se pone al día
        $this->rango($r->json('pendiente_desde'), '2026-06')->assertCreated()->assertJsonPath('depreciados', 4);
    }

    // ------------------------------------------------------------- simular

    public function test_simular_muestra_la_lista_y_el_total_sin_registrar_nada(): void
    {
        $this->activo();
        $this->activo(['nombre' => 'Escritorio', 'costo' => 600]);
        $asientos = JournalEntry::count();

        $r = $this->mes('2026-01', simular: true)->assertOk();

        $r->assertJsonPath('simulado', true)->assertJsonPath('depreciados', 2);
        $this->assertEquals(150, $r->json('total'));
        $this->assertCount(2, $r->json('meses.0.activos'));
        $this->assertNull($r->json('meses.0.asiento'));
        $this->assertSame(0, FixedAssetDepreciation::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(0, $this->saldo('depreciacion_acumulada', -1));

        // Un rango simulado encadena los meses (el segundo se calcula como si el primero ya estuviera) y tampoco registra
        $r = $this->rango('2026-01', '2026-03', simular: true)->assertOk();
        $this->assertSame(6, $r->json('depreciados'));
        $this->assertEquals(450, $r->json('total'));
        $this->assertSame(0, FixedAssetDepreciation::count());
        $this->assertSame($asientos, JournalEntry::count());

        // Y luego el mes se contabiliza normalmente
        $this->mes('2026-01')->assertCreated()->assertJsonPath('depreciados', 2);
    }

    // ------------------------------------------------------------- períodos cerrados

    public function test_un_periodo_cerrado_da_422_y_no_se_crea_nada(): void
    {
        $this->activo();
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 2, 'estado' => 'cerrado']);
        $asientos = JournalEntry::count();

        $this->rango('2026-01', '2026-03')->assertStatus(422)->assertJsonValidationErrors(['fecha']);

        // Ni siquiera enero, que sí estaba abierto: todo el rango se deshace
        $this->assertSame(0, FixedAssetDepreciation::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(0, $this->saldo('depreciacion_acumulada', -1));

        // Enero sí se puede depreciar solo; febrero, que está cerrado, no
        $this->mes('2026-01')->assertCreated();
        $this->mes('2026-02')->assertStatus(422)->assertJsonValidationErrors(['fecha']);
        $this->assertSame(1, FixedAssetDepreciation::count());
        $this->assertCount(1, $this->depreciaciones());
    }

    // ------------------------------------------------------------- chequeo contable

    public function test_el_chequeo_estricto_y_la_auditoria_de_inventario_quedan_en_cero(): void
    {
        $this->activo();
        $this->activo(['nombre' => 'Camioneta', 'costo' => 24000, 'valor_residual' => 4000, 'vida_util_meses' => 60]);
        $this->rango('2026-01', '2026-03')->assertCreated();

        $this->chequeoEstricto()->expectsOutputToContain('Depreciación acumulada')->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_el_chequeo_estricto_detecta_una_depreciacion_que_no_cuadra_con_el_mayor(): void
    {
        $this->activo();
        $this->rango('2026-01', '2026-03')->assertCreated();

        // Se borra una fila del cronograma sin tocar el asiento: la suma de las fichas ya no coincide con la cuenta
        DB::table('fixed_asset_depreciations')->where('mes', 3)->delete();

        $this->chequeoEstricto()->expectsOutputToContain('DIFERENCIA')->assertExitCode(1);
        // Sin --estricto la diferencia se muestra pero no hace fallar
        $this->artisan('contable:chequeo', ['--company' => $this->company->id])->assertExitCode(0);
    }

    public function test_el_chequeo_estricto_detecta_un_valor_en_libros_negativo(): void
    {
        $a = $this->activo();
        $this->rango('2026-01', '2026-03')->assertCreated();

        // Un costo menor que lo ya depreciado dejaría el activo en negativo
        DB::table('fixed_assets')->where('id', $a->id)->update(['costo' => 250]);

        $this->chequeoEstricto()->expectsOutputToContain('negativo')->assertExitCode(1);
    }

    public function test_el_chequeo_compara_el_costo_de_las_fichas_con_el_mayor_solo_como_informacion(): void
    {
        $this->activo(['registrar_alta' => false, 'cuenta_contrapartida_id' => null]);

        // La ficha dice 1200 y el mayor de Activos fijos 0: se avisa, pero no hace fallar
        $this->chequeoEstricto()->expectsOutputToContain('costo de las fichas')->assertExitCode(0);
    }

    public function test_solo_se_deprecian_los_activos_de_la_empresa_pedida(): void
    {
        $this->activo();
        $ajeno = $this->postJson('/api/fixed-assets', $this->datosActivo(['company_id' => $this->otra->id, 'nombre' => 'De otra', 'cuenta_contrapartida_id' => $this->cuentaId('capital', $this->otra)]))->assertCreated();

        $this->mes('2026-01')->assertCreated()->assertJsonPath('depreciados', 1);

        $this->assertSame(1, FixedAssetDepreciation::count());
        $this->assertSame(0, FixedAssetDepreciation::where('company_id', $this->otra->id)->count());
        $this->assertSame(0, FixedAsset::findOrFail($ajeno->json('id'))->depreciaciones()->count());
        $this->assertSame(0, JournalEntry::where('company_id', $this->otra->id)->where('concepto', 'like', 'Depreciación%')->count());
    }
}
