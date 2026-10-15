<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;

/**
 * T7.2 · A1: la ficha del activo fijo. Código automático por empresa, alta contable contra la cuenta elegida, columnas calculadas,
 * edición (el costo y la vida útil se bloquean cuando ya hay depreciación) y eliminación.
 */
class ActivosFijosTest extends ActivosFijosTestCase
{
    // ------------------------------------------------------------- alta

    public function test_crea_un_activo_con_codigo_automatico_y_su_asiento_de_alta(): void
    {
        $r = $this->crear(['nombre' => 'Laptop Dell', 'serie' => 'SN-123', 'departamento' => 'Ventas'])->assertCreated();
        $r->assertJsonPath('codigo', 'AF-000001')->assertJsonPath('estado', 'activo')->assertJsonPath('metodo', 'linea_recta')
            ->assertJsonPath('grupo', 'equipos_computacion')->assertJsonPath('grupo_nombre', 'Equipos de computación')
            ->assertJsonPath('serie', 'SN-123')->assertJsonPath('departamento', 'Ventas')
            ->assertJsonPath('depreciacion_acumulada', 0)->assertJsonPath('valor_en_libros', 1200)
            ->assertJsonPath('cuota_mensual', 100)->assertJsonPath('meses_restantes', 12)->assertJsonPath('proximo_mes', '2026-01');
        $this->assertEquals(1200, $r->json('costo'));
        $this->assertSame('2026-01-01', $r->json('fecha_inicio_depreciacion'));

        $activo = FixedAsset::firstOrFail();
        // Debe Activos fijos / Haber la contrapartida elegida (Capital), fechado el día de la adquisición y con el activo de origen
        $alta = $this->asientoDe($activo);
        $this->assertLineas($alta, [['1.2.01', 1200, 0], ['3.1.01', 0, 1200]]);
        $this->assertSame('2026-01-10', $alta->fecha->toDateString());
        $this->assertSame('pendiente', $alta->estado);
        $this->assertSame($alta->id, (int) $activo->journal_entry_alta_id);

        // El siguiente código sigue la secuencia y se sugiere antes de crearlo
        $this->getJson("/api/fixed-assets/siguiente-codigo?company_id={$this->company->id}")->assertOk()->assertJsonPath('codigo', 'AF-000002');
        $this->crear(['nombre' => 'Escritorio'])->assertCreated()->assertJsonPath('codigo', 'AF-000002');

        // El plan central trae la cuenta 1.2.01 y su contrapartida de depreciación: se siembran con el resto del plan
        $this->assertSame(['1.2.01', '1.2.02'], \App\Models\Account::where('company_id', $this->company->id)
            ->whereIn('codigo', ['1.2.01', '1.2.02'])->orderBy('codigo')->pluck('codigo')->all());
        $this->assertSame('(-) Depreciación acumulada', \App\Models\Account::where('company_id', $this->company->id)->where('codigo', '1.2.02')->value('nombre'));
    }

    public function test_el_codigo_es_unico_por_empresa_y_se_puede_escribir_a_mano(): void
    {
        $this->crear(['codigo' => 'LAP-01'])->assertCreated()->assertJsonPath('codigo', 'LAP-01');
        $this->crear(['codigo' => 'LAP-01'])->assertStatus(422)->assertJsonValidationErrors(['codigo']);
        $this->assertSame(1, FixedAsset::count());

        // La otra empresa puede usar el mismo código
        $this->postJson('/api/fixed-assets', $this->datosActivo(['company_id' => $this->otra->id, 'codigo' => 'LAP-01', 'cuenta_contrapartida_id' => $this->cuentaId('capital', $this->otra)]))
            ->assertCreated();
        $this->assertSame(2, FixedAsset::count());

        // Un código escrito a mano que no sigue la secuencia AF-000001 no la altera
        $this->crear()->assertCreated()->assertJsonPath('codigo', 'AF-000001');
    }

    public function test_valida_los_datos_del_activo_y_no_deja_nada_a_medias(): void
    {
        $this->crear(['valor_residual' => 1300])->assertStatus(422)->assertJsonValidationErrors(['valor_residual']);
        $this->crear(['vida_util_meses' => 0])->assertStatus(422)->assertJsonValidationErrors(['vida_util_meses']);
        $this->crear(['costo' => 0])->assertStatus(422)->assertJsonValidationErrors(['costo']);
        $this->crear(['grupo' => 'naves_espaciales'])->assertStatus(422)->assertJsonValidationErrors(['grupo']);
        $this->crear(['metodo' => 'saldo_decreciente'])->assertStatus(422)->assertJsonValidationErrors(['metodo']);
        $this->crear(['nombre' => ''])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        // No se empieza a depreciar antes de adquirir el activo
        $this->crear(['fecha_inicio_depreciacion' => '2025-12-01'])->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio_depreciacion']);
        // El responsable debe ser un empleado de esta empresa
        $ajeno = Employee::create(['company_id' => $this->otra->id, 'cedula' => '1700000001', 'nombres' => 'Ajeno', 'fecha_ingreso' => '2025-01-01', 'sueldo' => 500]);
        $this->crear(['employee_id' => $ajeno->id])->assertStatus(422)->assertJsonValidationErrors(['employee_id']);

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_el_valor_residual_por_defecto_es_cero_y_el_inicio_es_el_mes_de_la_adquisicion(): void
    {
        $datos = $this->datosActivo();
        unset($datos['valor_residual'], $datos['fecha_inicio_depreciacion']);
        $r = $this->postJson('/api/fixed-assets', $datos)->assertCreated();
        $this->assertEquals(0, $r->json('valor_residual'));
        $this->assertSame('2026-01-01', $r->json('fecha_inicio_depreciacion'));
    }

    public function test_sin_contrapartida_ni_compra_capitalizada_no_se_puede_registrar_el_alta(): void
    {
        $datos = $this->datosActivo();
        unset($datos['cuenta_contrapartida_id']);
        $this->postJson('/api/fixed-assets', $datos)->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, JournalEntry::count());

        // La cuenta por pagar se mueve desde las compras, no con un alta a mano; un encabezado del plan tampoco admite movimientos
        $this->crear(['cuenta_contrapartida_id' => $this->cuentaId('cxp')])->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        $encabezado = \App\Models\Account::where('company_id', $this->company->id)->where('codigo', '1.2')->value('id');
        $this->crear(['cuenta_contrapartida_id' => $encabezado])->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        // Ni la propia cuenta de activos fijos, ni una cuenta de otra empresa
        $this->crear(['cuenta_contrapartida_id' => $this->cuentaId('activos_fijos')])->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        $this->crear(['cuenta_contrapartida_id' => $this->cuentaId('capital', $this->otra)])->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_un_activo_que_ya_esta_en_la_contabilidad_se_registra_sin_asiento_de_alta(): void
    {
        $datos = $this->datosActivo(['registrar_alta' => false]);
        unset($datos['cuenta_contrapartida_id']);
        $r = $this->postJson('/api/fixed-assets', $datos)->assertCreated();

        $this->assertNull(FixedAsset::findOrFail($r->json('id'))->journal_entry_alta_id);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_el_alta_en_un_periodo_cerrado_da_422_y_no_deja_la_ficha(): void
    {
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 1, 'estado' => 'cerrado']);

        $this->crear()->assertStatus(422)->assertJsonValidationErrors(['fecha']);

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, JournalEntry::count());
    }

    // ------------------------------------------------------------- listado y ficha

    public function test_el_listado_trae_depreciacion_acumulada_valor_en_libros_y_meses_restantes(): void
    {
        $laptop = $this->activo(['nombre' => 'Laptop']);
        $camion = $this->activo(['nombre' => 'Camioneta', 'grupo' => 'vehiculos', 'costo' => 24000, 'valor_residual' => 4000, 'vida_util_meses' => 60,
            'fecha_adquisicion' => '2026-02-01', 'fecha_inicio_depreciacion' => '2026-03-01']);
        $this->rango('2026-01', '2026-03')->assertCreated();   // laptop: 3 meses; camioneta: 1 mes (marzo)

        $r = $this->getJson("/api/fixed-assets?company_id={$this->company->id}")->assertOk();
        $filas = collect($r->json('activos'))->keyBy('codigo');
        $a = $filas[$laptop->codigo];
        $this->assertEquals(300, $a['depreciacion_acumulada']);
        $this->assertEquals(900, $a['valor_en_libros']);
        $this->assertSame(9, $a['meses_restantes']);
        $this->assertSame(3, $a['meses_depreciados']);
        $this->assertEquals(100, $a['cuota_mensual']);
        $this->assertSame('2026-04', $a['proximo_mes']);
        $this->assertFalse($a['puede_editar_costos']);
        $this->assertFalse($a['puede_eliminar']);

        $c = $filas[$camion->codigo];
        $this->assertEquals(333.33, $c['depreciacion_acumulada']);   // (24000 − 4000) / 60
        $this->assertEquals(23666.67, $c['valor_en_libros']);
        $this->assertSame(59, $c['meses_restantes']);
        $this->assertEquals(333.33, $c['cuota_mensual']);

        $this->assertEquals(25200, $r->json('totales.costo'));
        $this->assertEquals(633.33, $r->json('totales.depreciacion_acumulada'));
        $this->assertEquals(24566.67, $r->json('totales.valor_en_libros'));
        $this->assertSame(2, $r->json('totales.activos'));
        $this->assertContains('vehiculos', collect($r->json('grupos'))->pluck('valor')->all());
    }

    public function test_filtra_por_grupo_estado_y_texto_y_solo_ve_los_de_su_empresa(): void
    {
        $this->activo(['nombre' => 'Laptop Dell', 'serie' => 'ABC-1']);
        $this->activo(['nombre' => 'Escritorio', 'grupo' => 'muebles_enseres']);
        $this->postJson('/api/fixed-assets', $this->datosActivo(['company_id' => $this->otra->id, 'nombre' => 'De otra', 'cuenta_contrapartida_id' => $this->cuentaId('capital', $this->otra)]))->assertCreated();

        $lista = fn (string $q) => collect($this->getJson("/api/fixed-assets?company_id={$this->company->id}&{$q}")->assertOk()->json('activos'))->pluck('nombre')->all();
        $this->assertSame(['Laptop Dell', 'Escritorio'], $lista(''));
        $this->assertSame(['Escritorio'], $lista('grupo=muebles_enseres'));
        $this->assertSame(['Laptop Dell'], $lista('q=abc-1'));
        $this->assertSame(['Escritorio'], $lista('q=escri'));
        $this->assertSame([], $lista('estado=dado_de_baja'));
        $this->assertSame(['Laptop Dell', 'Escritorio'], $lista('estado=activo'));
    }

    public function test_la_ficha_trae_el_cronograma_con_lo_contabilizado_y_lo_pendiente(): void
    {
        $a = $this->activo();
        $this->rango('2026-01', '2026-02')->assertCreated();

        $r = $this->getJson("/api/fixed-assets/{$a->id}?company_id={$this->company->id}")->assertOk();
        $cron = $r->json('cronograma');
        $this->assertCount(12, $cron);
        $this->assertSame('2026-01', $cron[0]['periodo']);
        $this->assertSame('contabilizado', $cron[0]['estado']);
        $this->assertEquals(100, $cron[0]['monto']);
        $this->assertEquals(100, $cron[0]['acumulada']);
        $this->assertEquals(1100, $cron[0]['valor_en_libros']);
        $this->assertNotEmpty($cron[0]['asiento']);
        $this->assertSame('contabilizado', $cron[1]['estado']);
        $this->assertSame('pendiente', $cron[2]['estado']);
        $this->assertSame('2026-03', $cron[2]['periodo']);
        $this->assertNull($cron[2]['asiento']);
        $this->assertEquals(1200, $cron[11]['acumulada']);
        $this->assertEquals(0, $cron[11]['valor_en_libros']);
        $this->assertSame('2026-12', $cron[11]['periodo']);
    }

    // ------------------------------------------------------------- edición

    public function test_edita_los_datos_generales_aunque_ya_tenga_depreciacion_y_el_responsable_es_un_empleado_de_la_empresa(): void
    {
        $a = $this->activo();
        $this->mes('2026-01')->assertCreated();
        $emp = Employee::create(['company_id' => $this->company->id, 'cedula' => '1700000002', 'nombres' => 'María Pérez', 'fecha_ingreso' => '2025-01-01', 'sueldo' => 600]);

        // La pantalla manda la ficha completa: lo que no cambió no cuenta como cambio
        $datos = $this->datosActivo(['nombre' => 'Laptop Dell XPS', 'employee_id' => $emp->id, 'departamento' => 'Gerencia', 'observacion' => 'Asignada a gerencia']);
        $this->putJson("/api/fixed-assets/{$a->id}", $datos)->assertOk()
            ->assertJsonPath('nombre', 'Laptop Dell XPS')->assertJsonPath('responsable', 'María Pérez')->assertJsonPath('departamento', 'Gerencia');
        $this->assertSame($emp->id, (int) $a->fresh()->employee_id);
    }

    public function test_con_depreciacion_contabilizada_el_costo_la_vida_util_y_el_residual_no_cambian(): void
    {
        $a = $this->activo();
        $this->mes('2026-01')->assertCreated();

        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['costo' => 1300]))->assertStatus(422)->assertJsonValidationErrors(['costo']);
        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['vida_util_meses' => 24]))->assertStatus(422)->assertJsonValidationErrors(['vida_util_meses']);
        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['valor_residual' => 100]))->assertStatus(422)->assertJsonValidationErrors(['valor_residual']);
        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['fecha_inicio_depreciacion' => '2026-02-01']))->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio_depreciacion']);

        $a->refresh();
        $this->assertEquals(1200, $a->costo);
        $this->assertSame(12, (int) $a->vida_util_meses);
    }

    public function test_antes_de_depreciar_cambiar_el_costo_reversa_el_alta_y_registra_la_nueva(): void
    {
        $a = $this->activo();

        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['costo' => 1500, 'vida_util_meses' => 24]))->assertOk()
            ->assertJsonPath('vida_util_meses', 24)->assertJsonPath('cuota_mensual', 62.5);

        $a->refresh();
        $this->assertEquals(1500, $a->costo);
        // Alta original (1200), su contra-asiento y el alta nueva (1500): el mayor de Activos fijos queda en 1500
        $this->assertSame(3, JournalEntry::count());
        $this->assertEquals(1500, $this->saldo('activos_fijos'));
        $this->assertEquals(1500, $this->saldo('capital', -1));
        $nueva = JournalEntry::findOrFail($a->journal_entry_alta_id);
        $this->assertLineas($nueva, [['1.2.01', 1500, 0], ['3.1.01', 0, 1500]]);
        $this->assertSame('2026-01-10', $nueva->fecha->toDateString());
        $this->assertStringStartsWith('Reversión', JournalEntry::where('concepto', 'like', 'Reversión%')->value('concepto'));
    }

    public function test_cambiar_solo_la_vida_util_antes_de_depreciar_no_toca_la_contabilidad(): void
    {
        $a = $this->activo();

        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['vida_util_meses' => 24, 'valor_residual' => 200]))->assertOk()
            ->assertJsonPath('cuota_mensual', 41.67);

        $this->assertSame(1, JournalEntry::count());
    }

    public function test_editar_con_la_empresa_equivocada_da_404(): void
    {
        $a = $this->activo();
        $this->putJson("/api/fixed-assets/{$a->id}", $this->datosActivo(['company_id' => $this->otra->id]))->assertStatus(404);
    }

    // ------------------------------------------------------------- eliminar

    public function test_elimina_un_activo_sin_depreciacion_y_reversa_su_alta(): void
    {
        $a = $this->activo();

        $this->deleteJson("/api/fixed-assets/{$a->id}?company_id={$this->company->id}")->assertNoContent();

        $this->assertSame(0, FixedAsset::count());
        $this->assertEquals(0, $this->saldo('activos_fijos'));
        $this->assertSame(2, JournalEntry::count());   // el alta y su reversión se conservan como rastro
        $this->assertSame(1, JournalEntry::where('concepto', 'like', 'Reversión%')->count());
    }

    public function test_no_elimina_un_activo_con_depreciacion_ni_uno_dado_de_baja(): void
    {
        $a = $this->activo();
        $this->mes('2026-01')->assertCreated();
        $this->deleteJson("/api/fixed-assets/{$a->id}?company_id={$this->company->id}")->assertStatus(422);
        $this->assertSame(1, FixedAsset::count());
        $this->assertSame(1, FixedAssetDepreciation::count());

        $b = $this->activo(['nombre' => 'Otro', 'fecha_inicio_depreciacion' => '2026-09-01', 'fecha_adquisicion' => '2026-09-01']);
        $this->baja($b, ['fecha' => '2026-09-20'])->assertOk();
        $this->deleteJson("/api/fixed-assets/{$b->id}?company_id={$this->company->id}")->assertStatus(422);
        $this->assertSame(2, FixedAsset::count());
    }

    // ------------------------------------------------------------- permisos

    public function test_solo_administrador_y_contador_y_solo_en_el_plan_completo(): void
    {
        $a = $this->activo();

        $this->como('cajero');
        $this->getJson("/api/fixed-assets?company_id={$this->company->id}")->assertForbidden();
        $this->crear()->assertForbidden();
        $this->mes('2026-01')->assertForbidden();
        $this->baja($a)->assertForbidden();

        $this->como('admin');
        $this->getJson("/api/fixed-assets?company_id={$this->company->id}")->assertOk();

        // Un plan sin el módulo: 402 en todas las rutas
        $this->como('contador');
        foreach (['negocio', 'basico'] as $plan) {
            $this->company->update(['plan' => $plan]);
            $this->getJson("/api/fixed-assets?company_id={$this->company->id}")->assertStatus(402);
            $this->crear()->assertStatus(402);
            $this->mes('2026-01')->assertStatus(402);
            $this->baja($a)->assertStatus(402);
        }
        $this->assertSame(1, FixedAsset::count());
        $this->assertContains('activos_fijos', config('planes.completo.features'));
        $this->assertNotContains('activos_fijos', config('planes.negocio.features'));
        $this->assertNotContains('activos_fijos', config('planes.basico.features'));
    }

    public function test_los_precios_de_los_planes_no_cambian(): void
    {
        $this->assertSame([85, 145, 225], [config('planes.basico.precio_anual'), config('planes.negocio.precio_anual'), config('planes.completo.precio_anual')]);
        $this->assertSame([55, 92, 142], [config('planes.basico.precio_semestral'), config('planes.negocio.precio_semestral'), config('planes.completo.precio_semestral')]);
    }

    public function test_un_activo_de_otra_empresa_no_se_ve_ni_se_toca(): void
    {
        $a = $this->activo();
        $this->getJson("/api/fixed-assets/{$a->id}?company_id={$this->otra->id}")->assertNotFound();
        $this->deleteJson("/api/fixed-assets/{$a->id}?company_id={$this->otra->id}")->assertNotFound();
        $this->postJson("/api/fixed-assets/{$a->id}/baja", ['company_id' => $this->otra->id, 'fecha' => '2026-04-10', 'motivo' => 'x'])->assertNotFound();
        $this->assertSame(1, FixedAsset::count());
    }
}
