<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Payroll;
use App\Support\VacacionesEmpleado;
use Carbon\Carbon;

/**
 * T7.1 / N3: permisos y vacaciones por empleado, saldo de vacaciones y descuento de los permisos sin sueldo en el rol del mes.
 * "Hoy" es el miércoles 2026-10-14 (ver NominaTestCase): el lunes 5 de octubre y el lunes 12 son días hábiles de verdad.
 */
class PermisosVacacionesTest extends NominaTestCase
{
    private function ausencia(Employee $e, array $datos)
    {
        return $this->postJson('/api/employee-leaves', $datos + ['company_id' => $e->company_id, 'employee_id' => $e->id]);
    }

    // ------------------------------------------------------------------ registrar y días

    public function test_los_dias_son_los_habiles_entre_las_dos_fechas_salvo_que_se_escriban(): void
    {
        $e = $this->empleado(800);

        // Lunes 12 a viernes 16 = 5 días; con sábado y domingo en medio, de lunes 12 a lunes 19 = 6
        $r = $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-16'])->assertCreated();
        $this->assertEquals(5, $r->json('dias'));
        $this->assertSame('aprobado', $r->json('estado'));
        $this->assertTrue($r->json('remunerado'));
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-19', 'fecha_hasta' => '2026-10-26'])->assertCreated();
        $this->assertEquals(6, $r->json('dias'));

        // Medio día: se escribe
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-11-02', 'fecha_hasta' => '2026-11-02', 'dias' => 0.5, 'remunerado' => false])->assertCreated();
        $this->assertEquals(0.5, $r->json('dias'));
        $this->assertFalse($r->json('remunerado'));

        // Un fin de semana no tiene días hábiles: hay que escribir los días
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-11-07', 'fecha_hasta' => '2026-11-08'])
            ->assertStatus(422)->assertJsonValidationErrors('dias');
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-10', 'dias' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('dias');
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-09'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_hasta');
        $this->ausencia($e, ['tipo' => 'viaje', 'fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-10'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->assertSame(3, EmployeeLeave::count());
    }

    public function test_dos_ausencias_no_se_cruzan_y_el_empleado_debe_ser_de_la_empresa(): void
    {
        $e = $this->empleado(800);
        $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-16'])->assertCreated();
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-16', 'fecha_hasta' => '2026-10-20'])->assertStatus(422);
        $this->assertStringContainsString('12/10/2026 al 16/10/2026', $r->json('errors.fecha_desde.0'));

        // Una cancelada libera las fechas
        $primera = EmployeeLeave::firstOrFail();
        $this->putJson("/api/employee-leaves/{$primera->id}", ['company_id' => $this->company->id, 'estado' => 'cancelado'])->assertOk()->assertJsonPath('estado', 'cancelado');
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-16', 'fecha_hasta' => '2026-10-20'])->assertCreated();

        $otra = \App\Models\Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = $this->empleado(500, [], $otra);
        $this->postJson('/api/employee-leaves', ['company_id' => $this->company->id, 'employee_id' => $ajeno->id, 'tipo' => 'permiso',
            'fecha_desde' => '2026-12-01', 'fecha_hasta' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->putJson("/api/employee-leaves/{$primera->id}", ['company_id' => $otra->id, 'estado' => 'aprobado'])->assertNotFound();
        $this->getJson('/api/employee-leaves?company_id='.$otra->id)->assertOk()->assertJsonCount(0);
        $this->getJson("/api/employee-leaves?company_id={$this->company->id}&employee_id={$e->id}&estado=aprobado")->assertOk()->assertJsonCount(1);
    }

    // ------------------------------------------------------------------ saldo de vacaciones

    public function test_el_saldo_son_15_dias_por_año_de_servicio_menos_las_vacaciones_aprobadas(): void
    {
        $e = $this->empleado(800, ['fecha_ingreso' => '2025-01-01']);
        $url = "/api/employees/{$e->id}/vacaciones?company_id={$this->company->id}";

        // 12 meses completos = 15 días; sin tomar nada
        $s = $this->getJson($url.'&al=2026-01-01')->assertOk();
        $this->assertEquals([12, 15, 0, 15, 0], [$s->json('meses'), $s->json('ganados'), $s->json('tomados'), $s->json('saldo'), $s->json('debe')]);
        // Un mes incompleto no suma: 2026-01-31 son 12 meses y 30 días
        $this->assertEquals(15, $this->getJson($url.'&al=2026-01-31')->json('ganados'));
        // 6 meses = 7,5 días (1,25 por mes completo)
        $this->assertEquals(7.5, $this->getJson($url.'&al=2025-07-01')->json('ganados'));

        // Vacaciones aprobadas de 5 días; un permiso, una cancelada y una pendiente no restan
        $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2025-08-04', 'fecha_hasta' => '2025-08-08'])->assertCreated();
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2025-09-01', 'fecha_hasta' => '2025-09-02'])->assertCreated();
        $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2025-10-06', 'fecha_hasta' => '2025-10-10', 'estado' => 'pendiente'])->assertCreated();
        $cancelada = $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2025-11-03', 'fecha_hasta' => '2025-11-07'])->assertCreated();
        $this->putJson('/api/employee-leaves/'.$cancelada->json('id'), ['company_id' => $this->company->id, 'estado' => 'cancelado'])->assertOk();

        $s = $this->getJson($url.'&al=2026-01-01')->assertOk();
        $this->assertEquals([15, 5, 10, 0], [$s->json('ganados'), $s->json('tomados'), $s->json('saldo'), $s->json('debe')]);

        // Aprobar la pendiente la cuenta
        $pendiente = EmployeeLeave::where('estado', 'pendiente')->firstOrFail();
        $this->putJson("/api/employee-leaves/{$pendiente->id}", ['company_id' => $this->company->id, 'estado' => 'aprobado'])->assertOk();
        $this->assertEquals(5, $this->getJson($url.'&al=2026-01-01')->json('saldo'));
    }

    public function test_si_tomó_mas_de_lo_ganado_debe_dias_y_el_registro_lo_avisa(): void
    {
        $e = $this->empleado(800, ['fecha_ingreso' => '2026-04-01']);              // al 2026-10-14: 6 meses = 7,5 días
        $r = $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2026-10-19', 'fecha_hasta' => '2026-10-30'])->assertCreated();   // 10 días
        $this->assertEquals(10, $r->json('dias'));
        $this->assertSame(['El empleado debe 2.5 días de vacaciones (tomó más de los que ganó).'], $r->json('avisos'));

        $s = $this->getJson("/api/employees/{$e->id}/vacaciones?company_id={$this->company->id}")->assertOk();
        $this->assertEquals([7.5, 10, -2.5, 2.5], [$s->json('ganados'), $s->json('tomados'), $s->json('saldo'), $s->json('debe')]);
        $this->assertSame('2026-10-14', $s->json('al'));

        // Con 6 años y 9 meses de servicio (81 meses) son 81 x 1,25 = 101,25 días: no se agregan días extra por antigüedad (supuesto documentado)
        $vieja = $this->empleado(800, ['fecha_ingreso' => '2020-01-01']);
        $this->assertEquals(101.25, $this->getJson("/api/employees/{$vieja->id}/vacaciones?company_id={$this->company->id}")->json('ganados'));
    }

    // ------------------------------------------------------------------ descuento en el rol

    public function test_un_permiso_sin_sueldo_se_descuenta_en_el_rol_a_sueldo_entre_30_por_los_dias(): void
    {
        $e = $this->empleado(800);
        // Lunes 5 a miércoles 7 de octubre: 3 días x (800 / 30) = 80.00
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-05', 'fecha_hasta' => '2026-10-07', 'remunerado' => false, 'observacion' => 'Trámite personal'])->assertCreated();

        $rol = $this->generar()->assertOk();
        $l = $this->linea($rol, $e);
        $this->assertEquals(80, $l['permisos']);
        $this->assertEquals(68.04, $l['aporte_personal'], 'El IESS se calcula sobre lo que de verdad se paga: 720');
        $this->assertEquals(651.96, $l['neto']);
        $this->assertEquals(80.28, $l['aporte_patronal']);
        $item = collect($l['detalle'])->firstWhere('tipo', 'permiso');
        $this->assertEquals([3, 80], [$item['dias'], $item['monto']]);
        $this->assertStringContainsString('05/10/2026 al 07/10/2026', $item['concepto']);
        $this->assertEquals(800, $rol->json('total_ingresos'));
        $this->assertEquals(148.04, $rol->json('total_egresos'), 'IESS 68.04 + permiso 80');

        $this->cerrar($rol->json('id'))->assertOk();
        // El gasto de sueldos es lo realmente pagado (800 - 80); el neto es lo que se paga
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 720, 0], ['5.2.02', 80.28, 0], ['5.2.03', 129.17, 0],
            ['2.1.04', 0, 148.32], ['2.1.05', 0, 129.17], ['2.1.07', 0, 651.96],
        ]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_permiso_remunerado_una_vacacion_o_una_ausencia_no_aprobada_no_descuentan(): void
    {
        $e = $this->empleado(800);
        $this->ausencia($e, ['tipo' => 'vacaciones', 'fecha_desde' => '2026-10-05', 'fecha_hasta' => '2026-10-07'])->assertCreated();                                 // remunerado
        $this->ausencia($e, ['tipo' => 'enfermedad', 'fecha_desde' => '2026-10-08', 'fecha_hasta' => '2026-10-08', 'remunerado' => true])->assertCreated();
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-12', 'remunerado' => false, 'estado' => 'pendiente'])->assertCreated();
        $cancelado = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-13', 'fecha_hasta' => '2026-10-13', 'remunerado' => false])->assertCreated();
        $this->putJson('/api/employee-leaves/'.$cancelado->json('id'), ['company_id' => $this->company->id, 'estado' => 'cancelado'])->assertOk();

        $l = $this->linea($this->generar()->assertOk(), $e);
        $this->assertEquals(0, $l['permisos']);
        $this->assertEquals([], collect($l['detalle'])->where('tipo', 'permiso')->all());
        $this->assertEquals(724.40, $l['neto'], 'Sin descuentos: 800 menos el IESS de 75.60');
    }

    public function test_un_permiso_que_cruza_de_mes_reparte_sus_dias_por_dias_habiles_en_cada_mes(): void
    {
        $e = $this->empleado(800);
        // Miércoles 30 de septiembre al viernes 2 de octubre: 3 días hábiles (1 en septiembre, 2 en octubre)
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-09-30', 'fecha_hasta' => '2026-10-02', 'remunerado' => false])->assertCreated();
        $this->assertEquals(3, $r->json('dias'));
        $leave = EmployeeLeave::firstOrFail();
        $this->assertEquals([1, 2, 0], [VacacionesEmpleado::diasEnMes($leave, 2026, 9), VacacionesEmpleado::diasEnMes($leave, 2026, 10), VacacionesEmpleado::diasEnMes($leave, 2026, 11)]);

        $this->assertEquals(26.67, $this->linea($this->generar($this->anio, 9)->assertOk(), $e)['permisos']);    // 800 / 30 x 1
        $this->assertEquals(53.33, $this->linea($this->generar($this->anio, 10)->assertOk(), $e)['permisos']);   // 800 / 30 x 2
        $this->assertEquals(0, $this->linea($this->generar($this->anio, 11)->assertOk(), $e)['permisos']);
    }

    public function test_cancelar_el_permiso_antes_de_cerrar_quita_el_descuento_y_uno_registrado_en_un_mes_cerrado_avisa(): void
    {
        $e = $this->empleado(800);
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-05', 'fecha_hasta' => '2026-10-05', 'remunerado' => false])->assertCreated();
        $this->assertSame([], $r->json('avisos'));
        $this->assertEquals(26.67, $this->linea($this->generar()->assertOk(), $e)['permisos']);

        $this->putJson('/api/employee-leaves/'.$r->json('id'), ['company_id' => $this->company->id, 'estado' => 'cancelado'])->assertOk();
        $this->assertEquals(0, $this->linea($this->generar()->assertOk(), $e)['permisos']);

        // Con el rol de octubre cerrado, un permiso sin sueldo de octubre no se descontará: se avisa
        $this->cerrar(Payroll::firstOrFail()->id)->assertOk();
        $r = $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-06', 'fecha_hasta' => '2026-10-06', 'remunerado' => false])->assertCreated();
        $this->assertSame(['El rol de 10/2026 ya está cerrado: el descuento de ese mes no se aplicará.'], $r->json('avisos'));
    }

    public function test_el_permiso_sin_sueldo_nunca_pasa_del_sueldo(): void
    {
        $e = $this->empleado(300);
        // Todo octubre sin sueldo: 31 días x (300 / 30) = 310, más que el sueldo; se topa en 300
        $this->ausencia($e, ['tipo' => 'permiso', 'fecha_desde' => '2026-10-01', 'fecha_hasta' => '2026-10-31', 'remunerado' => false, 'dias' => 31])->assertCreated();
        $l = $this->linea($this->generar()->assertOk(), $e);
        $this->assertEquals(300, $l['permisos']);
        $this->assertEquals(0, $l['aporte_personal']);
        $this->assertEquals(0, $l['neto']);
    }

    public function test_dias_habiles_cuenta_de_lunes_a_viernes(): void
    {
        $this->assertSame(5, VacacionesEmpleado::diasHabiles('2026-10-12', '2026-10-16'));
        $this->assertSame(0, VacacionesEmpleado::diasHabiles('2026-10-10', '2026-10-11'));
        $this->assertSame(1, VacacionesEmpleado::diasHabiles(Carbon::parse('2026-10-14'), Carbon::parse('2026-10-14')));
        $this->assertSame(22, VacacionesEmpleado::diasHabiles('2026-10-01', '2026-10-31'));
    }
}
