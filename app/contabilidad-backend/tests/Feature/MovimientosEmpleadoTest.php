<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\EmployeeMovement;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Payroll;

/**
 * T7.1 / N1: movimientos de empleado (anticipos, préstamos a cuotas, descuentos e ingresos extra) que el rol de pagos toma solo.
 * Cada prueba revisa montos y líneas reales del asiento, no solo el código HTTP.
 */
class MovimientosEmpleadoTest extends NominaTestCase
{
    // ------------------------------------------------------------------ registrar

    public function test_un_anticipo_entregado_en_efectivo_asienta_debe_anticipos_haber_caja(): void
    {
        $e = $this->empleado(1000);
        $r = $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo de quincena', 'monto' => 100,
            'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();

        $this->assertSame('anticipo', $r->json('tipo'));
        $this->assertTrue($r->json('entregado'));
        $this->assertEquals(100, $r->json('saldo'));
        $this->assertSame('pendiente', $r->json('estado'));
        $this->assertSame('2026-10-14', $r->json('fecha'));

        $m = EmployeeMovement::firstOrFail();
        $asiento = $this->asientoDe($m);
        $this->assertLineas($asiento, [['1.1.11', 100, 0], ['1.1.01', 0, 100]]);
        $this->assertStringContainsString('Anticipo a '.$e->nombres, $asiento->concepto);
        $this->assertSame(1, PaymentSplit::where('tipo', 'efectivo')->where('pagable_id', $m->id)->count());
        $this->assertSame(0, BankMovement::count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_anticipo_por_banco_deja_su_movimiento_de_banco_para_conciliar(): void
    {
        $e = $this->empleado(1000);
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Uno', 'numero_cuenta' => '123']);

        // Sin banco elegido no se entrega por transferencia
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo', 'monto' => 100, 'entrega' => ['forma_pago' => 'transferencia']])
            ->assertStatus(422)->assertJsonValidationErrors('entrega.bank_id');
        $this->assertSame(0, EmployeeMovement::count());

        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo', 'monto' => 100,
            'entrega' => ['forma_pago' => 'transferencia', 'bank_id' => $banco->id, 'documento' => 'TR-55']])->assertCreated();

        $m = EmployeeMovement::firstOrFail();
        $this->assertLineas($this->asientoDe($m), [['1.1.11', 100, 0], ['1.1.02', 0, 100]]);
        $mov = BankMovement::firstOrFail();
        $this->assertSame('debito', $mov->tipo);
        $this->assertEquals(100, (float) $mov->monto);
        $this->assertSame($banco->id, (int) $mov->bank_id);
        $this->assertSame('TR-55', $mov->documento);
        $this->assertSame($m->getMorphClass(), $mov->origen_type);
        $this->assertFalse((bool) $mov->conciliado);
        $this->chequeoEstricto()->assertExitCode(0);

        // El banco de otra empresa no sirve
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno', 'numero_cuenta' => '999']);
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo', 'monto' => 50, 'cuotas' => 2,
            'entrega' => ['forma_pago' => 'transferencia', 'bank_id' => $ajeno->id]])->assertStatus(422);
        $this->assertSame(1, EmployeeMovement::count());
    }

    public function test_un_descuento_o_un_ingreso_extra_no_mueven_caja_ni_bancos(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20])->assertCreated();
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'bono', 'concepto' => 'Bono de ventas', 'monto' => 60])->assertCreated();
        $this->assertSame(0, JournalEntry::count());

        // Solo un anticipo o un préstamo entrega dinero
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20, 'entrega' => ['forma_pago' => 'efectivo']])
            ->assertStatus(422)->assertJsonValidationErrors('entrega');
        // Un ingreso extra dice de qué es
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'concepto' => 'Algo', 'monto' => 10])->assertStatus(422)->assertJsonValidationErrors('subtipo');
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Cero', 'monto' => 0])->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->assertSame(2, EmployeeMovement::count());
    }

    public function test_solo_un_prestamo_lleva_cuotas_y_el_empleado_debe_ser_de_la_empresa(): void
    {
        $e = $this->empleado(1000);
        $r = $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo', 'monto' => 100, 'cuotas' => 5])->assertCreated();
        $this->assertSame(1, $r->json('cuotas'));
        $r = $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo', 'monto' => 100, 'cuotas' => 3])->assertCreated();
        $this->assertSame(3, $r->json('cuotas'));
        $this->assertEquals(33.33, $r->json('cuota'));

        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = $this->empleado(500, [], $otra);
        $this->postJson('/api/employee-movements', ['company_id' => $this->company->id, 'employee_id' => $ajeno->id, 'tipo' => 'descuento',
            'concepto' => 'X', 'fecha' => '2026-10-14', 'monto' => 5])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        // El listado es de la empresa pedida y se filtra por empleado, mes y estado
        $this->getJson('/api/employee-movements?company_id='.$otra->id)->assertOk()->assertJsonCount(0);
        $this->getJson('/api/employee-movements?company_id='.$this->company->id.'&employee_id='.$e->id.'&mes=2026-10&estado=pendiente')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/employee-movements?company_id='.$this->company->id.'&mes=2026-09')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/employee-movements?company_id='.$this->company->id.'&tipo=prestamo')->assertOk()->assertJsonCount(1);
    }

    // ------------------------------------------------------------------ el rol los toma solo

    public function test_generar_toma_los_movimientos_pero_solo_cerrar_baja_el_saldo(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20])->assertCreated();
        $m = EmployeeMovement::firstOrFail();

        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $this->assertEquals(20, $linea['descuentos']);
        $this->assertEquals(885.50, $linea['neto']);
        $this->assertSame('descuento', $linea['detalle'][0]['tipo']);
        $this->assertSame($m->id, $linea['detalle'][0]['id']);
        $this->assertEquals(20, $m->fresh()->saldo, 'Generar no baja el saldo');
        $this->assertSame('pendiente', $m->fresh()->estado);

        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals(0, $m->fresh()->saldo);
        $this->assertSame('aplicado', $m->fresh()->estado);
    }

    public function test_un_prestamo_a_tres_cuotas_descuenta_33_33_33_33_y_33_34_y_asienta_cada_una_contra_la_cuenta_de_prestamos(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo de emergencia', 'monto' => 100, 'cuotas' => 3,
            'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $prestamo = EmployeeMovement::firstOrFail();

        $esperado = [[10, 33.33, 66.67, 'pendiente'], [11, 33.33, 33.34, 'pendiente'], [12, 33.34, 0.0, 'aplicado']];
        foreach ($esperado as [$mes, $cuota, $saldo, $estado]) {
            $rol = $this->generar($this->anio, $mes)->assertOk();
            $linea = $this->linea($rol, $e);
            $this->assertEquals($cuota, $linea['prestamos'], "Cuota del mes $mes");
            $this->assertEquals(round(905.50 - $cuota, 2), $linea['neto']);
            $this->assertSame('Cuota '.($mes - 9).' de 3', $linea['detalle'][0]['cuota']);

            $this->cerrar($rol->json('id'))->assertOk();
            $this->assertEquals($saldo, $prestamo->fresh()->saldo, "Saldo tras el mes $mes");
            $this->assertSame($estado, $prestamo->fresh()->estado);

            $asiento = $this->asientoDe(Payroll::findOrFail($rol->json('id')));
            $this->assertLineas($asiento, [
                ['5.2.01', 1000, 0], ['5.2.02', 111.50, 0], ['5.2.03', 164.17, 0],
                ['2.1.04', 0, 206.00], ['2.1.05', 0, 164.17], ['1.1.11', 0, $cuota], ['2.1.07', 0, round(905.50 - $cuota, 2)],
            ]);
            $this->chequeoEstricto()->assertExitCode(0);
        }
    }

    public function test_un_descuento_mayor_al_neto_no_lo_deja_negativo_y_el_resto_pasa_al_mes_siguiente(): void
    {
        $e = $this->empleado(400);                // IESS 37.80: disponible 362.20
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo grande', 'monto' => 500])->assertCreated();
        $m = EmployeeMovement::firstOrFail();

        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $this->assertEquals(362.20, $linea['anticipos']);
        $this->assertEquals(0, $linea['neto']);
        $this->assertEquals(137.80, $linea['diferido'], 'Lo que no cabe se avisa en la línea');
        $this->assertEquals(137.80, $linea['detalle'][0]['diferido']);
        $this->assertEquals(500.00, $linea['detalle'][0]['debido']);

        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals(137.80, $m->fresh()->saldo);
        $this->assertSame('pendiente', $m->fresh()->estado);
        // El anticipo no salió por el sistema: se cancela contra Descuentos a empleados, y con neto cero no hay línea de sueldos por pagar
        $this->assertLineas($this->asientoDe(Payroll::findOrFail($rol->json('id'))), [
            ['5.2.01', 400, 0], ['5.2.02', 44.60, 0], ['5.2.03', 89.17, 0],
            ['2.1.04', 0, 82.40], ['2.1.05', 0, 89.17], ['2.1.06', 0, 362.20],
        ]);

        // El mes siguiente se descuenta lo que faltaba
        $rol2 = $this->generar($this->anio, 11)->assertOk();
        $linea2 = $this->linea($rol2, $e);
        $this->assertEquals(137.80, $linea2['anticipos']);
        $this->assertEquals(224.40, $linea2['neto']);
        $this->assertEquals(0, $linea2['diferido']);
        $this->cerrar($rol2->json('id'))->assertOk();
        $this->assertEquals(0, $m->fresh()->saldo);
        $this->assertSame('aplicado', $m->fresh()->estado);
    }

    public function test_los_ingresos_extra_pagan_iess_y_provisiones_como_las_horas_extra_de_siempre(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'horas_extra', 'concepto' => '8 horas extra', 'monto' => 40])->assertCreated();
        $rol = $this->generar()->assertOk();
        $l = $this->linea($rol, $e);
        $this->assertEquals(40, $l['horas_extra']);
        $this->assertEquals(98.28, $l['aporte_personal']);
        $this->assertEquals(115.96, $l['aporte_patronal']);
        $this->assertEquals(86.63, $l['decimo_tercero']);
        $this->assertEquals(43.37, $l['vacaciones']);
        $this->assertEquals(941.72, $l['neto']);
        $this->assertEquals(1040, $rol->json('total_ingresos'));

        // Comisiones y bonos van a su propia columna y también cuentan para el IESS
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'comisiones', 'concepto' => 'Comisión de ventas', 'monto' => 100])->assertCreated();
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'bono', 'concepto' => 'Bono de cumplimiento', 'monto' => 20])->assertCreated();
        $l = $this->linea($this->generar()->assertOk(), $e);
        $this->assertEquals([40, 100, 20], [$l['horas_extra'], $l['comisiones'], $l['otros_ingresos']]);
        $this->assertEquals(109.62, $l['aporte_personal']);
        $this->assertEquals(1050.38, $l['neto']);

        $this->cerrar(Payroll::firstOrFail()->id)->assertOk();
        $this->assertSame(3, EmployeeMovement::where('estado', 'aplicado')->count());
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 1160, 0], ['5.2.02', 129.34, 0], ['5.2.03', 184.17, 0],
            ['2.1.04', 0, 238.96], ['2.1.05', 0, 184.17], ['2.1.07', 0, 1050.38],
        ]);
    }

    public function test_un_prestamo_que_empieza_en_noviembre_no_entra_al_rol_de_octubre(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo', 'monto' => 60, 'cuotas' => 2, 'aplicar_desde' => '2026-11-20'])->assertCreated();
        $this->assertSame('2026-11-01', EmployeeMovement::firstOrFail()->aplicar_desde->toDateString());

        $this->assertEquals(0, $this->linea($this->generar($this->anio, 10)->assertOk(), $e)['prestamos']);
        $this->assertEquals(30, $this->linea($this->generar($this->anio, 11)->assertOk(), $e)['prestamos']);
    }

    public function test_el_parametro_antiguo_extras_sigue_funcionando(): void
    {
        $e = $this->empleado(1000);
        $rol = $this->generar(null, null, [$e->id => ['horas_extra' => 40, 'prestamos' => 20]])->assertOk();
        $l = $this->linea($rol, $e);
        $this->assertEquals([40, 20], [$l['horas_extra'], $l['prestamos']]);
        $this->assertEquals(921.72, $l['neto']);

        // Sin un movimiento detrás, lo escrito a mano se cancela contra Descuentos a empleados (como antes)
        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 1040, 0], ['5.2.02', 115.96, 0], ['5.2.03', 169.17, 0],
            ['2.1.04', 0, 214.24], ['2.1.05', 0, 169.17], ['2.1.06', 0, 20], ['2.1.07', 0, 921.72],
        ]);
    }

    // ------------------------------------------------------------------ cancelar

    public function test_cancelar_un_anticipo_entregado_reversa_su_asiento_y_su_movimiento_de_banco(): void
    {
        $e = $this->empleado(1000);
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Uno', 'numero_cuenta' => '123']);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo', 'monto' => 100,
            'entrega' => ['forma_pago' => 'transferencia', 'bank_id' => $banco->id]])->assertCreated();
        $m = EmployeeMovement::firstOrFail();

        $r = $this->postJson("/api/employee-movements/{$m->id}/cancelar", ['company_id' => $this->company->id, 'motivo' => 'Se registró dos veces'])->assertOk();
        $this->assertSame('cancelado', $r->json('estado'));
        $this->assertFalse($r->json('puede_cancelar'));
        $this->assertNotNull($m->fresh()->cancelado_at);
        $this->assertSame('Se registró dos veces', $m->fresh()->motivo_cancelacion);

        $reverso = JournalEntry::where('concepto', 'like', 'Reversión%')->firstOrFail();
        $this->assertLineas($reverso, [['1.1.02', 100, 0], ['1.1.11', 0, 100]]);
        $this->assertSame(['debito', 'credito'], BankMovement::orderBy('id')->pluck('tipo')->all());
        $this->chequeoEstricto()->assertExitCode(0);

        // Ya no entra al rol, y no se cancela dos veces
        $this->assertSame([], $this->linea($this->generar()->assertOk(), $e)['detalle']);
        $this->postJson("/api/employee-movements/{$m->id}/cancelar", ['company_id' => $this->company->id])->assertStatus(422);
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_no_se_cancela_un_movimiento_que_ya_se_descontó(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo', 'monto' => 100, 'cuotas' => 3, 'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20])->assertCreated();
        $this->cerrar($this->generar()->assertOk()->json('id'))->assertOk();
        $entradas = JournalEntry::count();

        foreach (EmployeeMovement::all() as $m) {
            $r = $this->postJson("/api/employee-movements/{$m->id}/cancelar", ['company_id' => $this->company->id])->assertStatus(422);
            $this->assertStringContainsString('ya se descontó', $r->json('errors.movimiento.0'));
            $this->assertNotSame('cancelado', $m->fresh()->estado);
        }
        $this->assertSame($entradas, JournalEntry::count(), 'No se reversó nada');

        // Un movimiento de otra empresa no existe
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $this->postJson('/api/employee-movements/'.EmployeeMovement::first()->id.'/cancelar', ['company_id' => $otra->id])->assertNotFound();
    }

    public function test_un_movimiento_cancelado_despues_de_generar_hace_fallar_el_cierre_sin_cambiar_nada(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20])->assertCreated();
        $m = EmployeeMovement::firstOrFail();
        $rol = $this->generar()->assertOk();

        $this->postJson("/api/employee-movements/{$m->id}/cancelar", ['company_id' => $this->company->id])->assertOk();
        $r = $this->cerrar($rol->json('id'))->assertStatus(422);
        $this->assertStringContainsString('Vuelve a generar el rol', $r->json('errors.payroll.0'));
        $this->assertSame('abierto', Payroll::firstOrFail()->estado);
        $this->assertSame(0, JournalEntry::count());

        // Al generar de nuevo ya no trae el movimiento cancelado y se puede cerrar
        $this->assertEquals(0, $this->linea($this->generar()->assertOk(), $e)['descuentos']);
        $this->cerrar(Payroll::firstOrFail()->id)->assertOk();
    }

    // ------------------------------------------------------------------ cerrar dos veces, mes contable cerrado, roles

    public function test_cerrar_dos_veces_o_generar_un_rol_cerrado_da_422_y_no_duplica_nada(): void
    {
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 50])->assertCreated();
        $rol = $this->generar()->assertOk();
        $this->cerrar($rol->json('id'))->assertOk();

        $segundo = $this->cerrar($rol->json('id'))->assertStatus(422);
        $this->assertStringContainsString('ya está cerrado', $segundo->json('errors.payroll.0'));
        $this->generar()->assertStatus(422);

        $this->assertSame(1, JournalEntry::count(), 'Un solo asiento por rol');
        $this->assertEquals(0, EmployeeMovement::firstOrFail()->saldo);
        $this->assertSame('cerrado', Payroll::firstOrFail()->estado);
        $this->assertSame(1, Payroll::firstOrFail()->lines()->count(), 'Las líneas del rol cerrado no se tocaron');
    }

    public function test_un_mes_contable_cerrado_rechaza_el_cierre_del_rol_y_todo_queda_como_estaba(): void
    {
        $e = $this->empleado(1000, ['contact_id' => ($cliente = $this->cliente())->id]);
        $factura = $this->factura($cliente, 30);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 20])->assertCreated();
        $rol = $this->generar()->assertOk();
        $this->assertEquals(30, $this->linea($rol, $e)['consumos']);

        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 10, 'estado' => AccountingPeriod::CERRADO]);
        $asientos = JournalEntry::count();

        $r = $this->cerrar($rol->json('id'))->assertStatus(422);
        $this->assertStringContainsString('está cerrado', $r->json('errors.fecha.0'));
        $this->assertSame('abierto', Payroll::firstOrFail()->estado);
        $this->assertEquals(20, EmployeeMovement::firstOrFail()->saldo);
        $this->assertSame('pendiente', EmployeeMovement::firstOrFail()->estado);
        $this->assertEquals(30, $factura->fresh()->saldo_pendiente);
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentSplit::count());
        $this->assertSame($asientos, JournalEntry::count());

        // Reabierto el mes, el mismo rol se cierra bien
        AccountingPeriod::query()->update(['estado' => AccountingPeriod::ABIERTO]);
        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals(0, $factura->fresh()->saldo_pendiente);
    }

    public function test_un_empleado_con_historial_no_se_elimina_pero_uno_limpio_si(): void
    {
        $con = $this->empleado(1000);
        $limpio = $this->empleado(900);
        $this->movimiento($con, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 5])->assertCreated();

        $r = $this->deleteJson("/api/employees/{$con->id}")->assertStatus(422);
        $this->assertStringContainsString('no se puede eliminar', $r->json('errors.employee.0'));
        $this->assertNotNull($con->fresh());
        $this->deleteJson("/api/employees/{$limpio->id}")->assertNoContent();
        $this->assertNull($limpio->fresh());

        // Con un rol generado tampoco
        $this->generar()->assertOk();
        $this->deleteJson("/api/employees/{$con->id}")->assertStatus(422);
    }

    public function test_el_cajero_no_toca_la_nomina_y_el_contador_si(): void
    {
        $e = $this->empleado(1000);
        $this->generar()->assertOk();
        $rolId = Payroll::firstOrFail()->id;
        $c = $this->company->id;

        $this->como('cajero');
        $this->getJson("/api/employees?company_id=$c")->assertForbidden();
        $this->getJson("/api/payrolls?company_id=$c")->assertForbidden();
        $this->getJson("/api/payrolls/$rolId?company_id=$c")->assertForbidden();
        $this->postJson('/api/payrolls/generate', ['company_id' => $c, 'anio' => 2026, 'mes' => 10])->assertForbidden();
        $this->postJson("/api/payrolls/$rolId/close", ['company_id' => $c])->assertForbidden();
        $this->postJson("/api/payrolls/$rolId/consumos", ['company_id' => $c, 'employee_id' => $e->id, 'excluir' => []])->assertForbidden();
        $this->getJson("/api/payrolls/$rolId/rol/{$e->id}?company_id=$c")->assertForbidden();
        $this->getJson("/api/payrolls/$rolId/rol-general?company_id=$c")->assertForbidden();
        $this->getJson("/api/employee-movements?company_id=$c")->assertForbidden();
        $this->postJson('/api/employee-movements', ['company_id' => $c, 'employee_id' => $e->id, 'tipo' => 'descuento', 'concepto' => 'X', 'fecha' => '2026-10-14', 'monto' => 5])->assertForbidden();
        $this->getJson("/api/employee-leaves?company_id=$c")->assertForbidden();
        $this->getJson("/api/employees/{$e->id}/vacaciones?company_id=$c")->assertForbidden();
        $this->assertSame(0, EmployeeMovement::count());

        $this->como('contador');
        $this->getJson("/api/employees?company_id=$c")->assertOk();
        $this->como('admin');
        $this->getJson("/api/employee-movements?company_id=$c")->assertOk();
    }
}
