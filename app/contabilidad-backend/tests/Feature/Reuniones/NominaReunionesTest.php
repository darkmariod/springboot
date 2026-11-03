<?php

namespace Tests\Feature\Reuniones;

use App\Models\BankMovement;
use App\Models\EmployeeLeave;
use App\Models\EmployeeMovement;
use App\Models\Payroll;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\NominaTestCase;

/**
 * Nómina en las reuniones del 10 de julio y del 18 de septiembre: anticipos, préstamos en cuotas, consumos de la empresa
 * descontados del sueldo, permisos, vacaciones y el rol de pagos. Usa `NominaTestCase` (el «hoy» queda fijo en el 14 de octubre de 2026).
 */
#[Group('reuniones')]
class NominaReunionesTest extends NominaTestCase
{
    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «A un empleado le doy un anticipo y el sistema lo tiene que descontar solo cuando haga el rol.»
     * Se prueba: anticipo de $100 en efectivo (Debe 1.1.11 / Haber Caja); el rol de octubre lo descuenta y al cerrar el saldo queda en cero.
     */
    #[Group('reuniones')]
    #[Group('R18')]
    public function test_R18_el_anticipo_se_entrega_con_su_asiento_y_el_rol_lo_descuenta_solo(): void
    {
        // Arrange: empleado de $1000 y anticipo entregado en efectivo
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo de quincena', 'monto' => 100, 'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $anticipo = EmployeeMovement::firstOrFail();
        $this->assertLineas($this->asientoDe($anticipo), [['1.1.11', 100, 0], ['1.1.01', 0, 100]]);

        // Act: se genera el rol de octubre
        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);

        // Assert: IESS 94.50 sobre $1000 y el anticipo de $100 → neto $805.50; generar no baja el saldo, cerrar sí
        $this->assertEquals([1000, 94.5, 100, 805.5], [$linea['sueldo'], $linea['aporte_personal'], $linea['anticipos'], $linea['neto']]);
        $this->assertEquals(100, $anticipo->fresh()->saldo);
        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals(0, $anticipo->fresh()->saldo);
        $this->assertSame('aplicado', $anticipo->fresh()->estado);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Un préstamo al empleado a tres cuotas: que cada mes descuente la cuota sola hasta que se pague.»
     * Se prueba: préstamo de $100 a 3 cuotas descuenta 33.33, 33.33 y 33.34 en octubre, noviembre y diciembre y su saldo baja a 66.67, 33.34 y 0.
     */
    #[Group('reuniones')]
    #[Group('R18')]
    public function test_R18_el_prestamo_a_tres_cuotas_descuenta_la_cuota_cada_mes_hasta_pagarse(): void
    {
        // Arrange
        $e = $this->empleado(1000);
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo de emergencia', 'monto' => 100, 'cuotas' => 3, 'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $prestamo = EmployeeMovement::firstOrFail();

        // Act + Assert: tres roles seguidos
        foreach ([[10, 33.33, 66.67, 'pendiente'], [11, 33.33, 33.34, 'pendiente'], [12, 33.34, 0.0, 'aplicado']] as [$mes, $cuota, $saldo, $estado]) {
            $rol = $this->generar($this->anio, $mes)->assertOk();
            $linea = $this->linea($rol, $e);
            $this->assertEquals($cuota, $linea['prestamos'], "Cuota del mes $mes");
            $this->assertEquals(round(905.50 - $cuota, 2), $linea['neto']);
            $this->assertSame('Cuota '.($mes - 9).' de 3', $linea['detalle'][0]['cuota']);
            $this->cerrar($rol->json('id'))->assertOk();
            $this->assertEquals($saldo, $prestamo->fresh()->saldo, "Saldo tras el mes $mes");
            $this->assertSame($estado, $prestamo->fresh()->estado);
            $this->chequeoEstricto()->assertExitCode(0);
        }
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Lo que el empleado consume en la empresa se le factura, y esa factura debe descontarse sola del rol de pagos.»
     * Se prueba: factura de $50 a crédito al empleado; el rol la lista como consumo, al cerrar la factura queda en cero sin mover caja.
     */
    #[Group('reuniones')]
    #[Group('R18')]
    public function test_R18_el_consumo_facturado_al_empleado_se_descuenta_solo_en_el_rol(): void
    {
        // Arrange: el empleado es también cliente de la empresa y tiene una factura abierta de $50
        $cliente = $this->cliente();
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);       // IESS 41.74 → neto 400.00
        $factura = $this->factura($cliente, 50);

        // Act: se genera el rol y se cierra
        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $consumo = collect($linea['detalle'])->firstWhere('tipo', 'consumo');
        $this->assertEquals([50, 350], [$linea['consumos'], $linea['neto']]);
        $this->assertSame($factura->numero, $consumo['numero']);
        $this->assertEquals(50, $factura->fresh()->saldo_pendiente, 'Generar no cobra la factura');
        $this->cerrar($rol->json('id'))->assertOk();

        // Assert: la factura queda en cero, descontada del rol, y el estado de cuenta lo dice
        $this->assertEquals(0, $factura->fresh()->saldo_pendiente);
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 441.74, 0], ['5.2.02', 49.25, 0], ['5.2.03', 94.39, 0],
            ['2.1.04', 0, 90.99], ['2.1.05', 0, 94.39], ['1.1.03', 0, 50], ['2.1.07', 0, 350],
        ]);
        $doc = $this->getJson("/api/estado-cuenta/clientes/{$cliente->id}?company_id={$this->company->id}")->assertOk()->json('documentos.0');
        $this->assertEquals(0, $doc['saldo']);
        $this->assertSame('descuento_rol', $doc['cobros'][0]['forma']);
        $this->assertSame(0, BankMovement::count(), 'No movió caja ni bancos');
        $this->chequeoEstricto()->assertExitCode(0);
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Permisos y vacaciones por empleado: cuántos días le quedan, y que un permiso sin sueldo se descuente en el rol.»
     * Se prueba: 12 meses dan 15 días; 5 días de vacaciones dejan 10; un permiso sin sueldo de 3 días descuenta $80 en el rol de octubre.
     */
    #[Group('reuniones')]
    #[Group('R18')]
    public function test_R18_los_permisos_y_las_vacaciones_llevan_su_saldo_y_el_permiso_sin_sueldo_se_descuenta(): void
    {
        // Arrange: empleado de $800 que entró el 1 de enero de 2025
        $e = $this->empleado(800, ['fecha_ingreso' => '2025-01-01']);
        $ausencia = fn (array $datos) => $this->postJson('/api/employee-leaves', $datos + ['company_id' => $e->company_id, 'employee_id' => $e->id]);

        // Act + Assert 1: 15 días ganados por año, 5 de vacaciones aprobadas → saldo 10
        $ausencia(['tipo' => 'vacaciones', 'fecha_desde' => '2025-08-04', 'fecha_hasta' => '2025-08-08'])->assertCreated();
        $saldo = $this->getJson("/api/employees/{$e->id}/vacaciones?company_id={$this->company->id}&al=2026-01-01")->assertOk();
        $this->assertEquals([15, 5, 10, 0], [$saldo->json('ganados'), $saldo->json('tomados'), $saldo->json('saldo'), $saldo->json('debe')]);

        // Act + Assert 2: un permiso sin sueldo de lunes 5 a miércoles 7 de octubre: 3 días × (800 / 30) = $80
        $ausencia(['tipo' => 'permiso', 'fecha_desde' => '2026-10-05', 'fecha_hasta' => '2026-10-07', 'remunerado' => false, 'observacion' => 'Trámite personal'])->assertCreated();
        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $this->assertEquals(80, $linea['permisos']);
        $this->assertEquals(651.96, $linea['neto']);
        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertSame(2, EmployeeLeave::count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «El rol de pagos de cada empleado impreso, con todo lo que se le paga y se le descuenta, y el neto en letras.»
     * Se prueba: el rol individual sale en PDF; el rol general se baja en CSV con el neto de cada empleado.
     */
    #[Group('reuniones')]
    #[Group('R18')]
    public function test_R18_el_rol_de_pagos_sale_impreso_con_el_neto(): void
    {
        // Arrange: empleado con anticipo y rol cerrado
        $e = $this->empleado(1000, ['nombres' => 'María Fernanda Pérez']);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo de quincena', 'monto' => 100])->assertCreated();
        $rol = $this->generar()->assertOk();
        $id = $rol->json('id');
        $this->cerrar($id)->assertOk();

        // Act
        $pdf = $this->get("/api/payrolls/$id/rol/{$e->id}?company_id={$this->company->id}")->assertOk();
        $general = $this->get("/api/payrolls/$id/rol-general?company_id={$this->company->id}&formato=csv")->assertOk();

        // Assert: el individual es un PDF con el nombre del empleado y el neto de $805.50
        $this->assertStringStartsWith('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $ruta = tempnam(sys_get_temp_dir(), 'rol').'.pdf';
            file_put_contents($ruta, $pdf->getContent());
            $texto = (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
            @unlink($ruta);
            foreach (['María Fernanda Pérez', 'Octubre 2026', '805.50', 'OCHOCIENTOS CINCO CON 50/100 DÓLARES'] as $esperado) {
                $this->assertStringContainsString($esperado, $texto, "El rol impreso debe decir «{$esperado}»");
            }
        }
        // Assert: el rol general trae al empleado con su neto
        $filas = array_map('str_getcsv', array_filter(explode("\n", substr($general->getContent(), 3))));
        $this->assertSame('Neto a pagar', end($filas[0]));
        $this->assertSame('María Fernanda Pérez', $filas[1][0]);
        $this->assertSame('805.50', end($filas[1]));
        $this->assertSame('TOTAL', $filas[2][0]);
    }
}
