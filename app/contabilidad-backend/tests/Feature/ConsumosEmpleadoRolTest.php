<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeMovement;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Payroll;

/**
 * T7.1 / N2: las facturas a crédito que la empresa le hizo al empleado (como cliente) aparecen solas como descuento del rol,
 * se pueden quitar antes de cerrar y, al cerrar, se cobran sin mover caja: baja el saldo de la factura y el asiento acredita la CxC.
 */
class ConsumosEmpleadoRolTest extends NominaTestCase
{
    public function test_una_factura_de_50_con_neto_de_400_se_descuenta_completa_y_deja_la_factura_en_cero(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);       // IESS 41.74 -> neto 400.00
        $factura = $this->factura($cliente, 50);

        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $this->assertEquals(50, $linea['consumos']);
        $this->assertEquals(350, $linea['neto']);
        $consumo = collect($linea['detalle'])->firstWhere('tipo', 'consumo');
        $this->assertSame($factura->id, $consumo['invoice_id']);
        $this->assertSame($factura->numero, $consumo['numero']);
        $this->assertEquals([50, 50], [$consumo['saldo'], $consumo['monto']]);
        $this->assertTrue($consumo['incluido']);
        $this->assertEquals(50, $factura->fresh()->saldo_pendiente, 'Generar no cobra la factura');

        $this->cerrar($rol->json('id'))->assertOk();

        $this->assertEquals(0, $factura->fresh()->saldo_pendiente);
        $pago = InvoicePayment::firstOrFail();
        $this->assertSame([$factura->id, 'descuento_rol', '2026-10-14'], [$pago->invoice_id, $pago->forma_pago, substr((string) $pago->fecha, 0, 10)]);
        $this->assertEquals(50, $pago->monto);
        $this->assertSame('Descontado en el rol de pagos 10/2026', $pago->nota);
        $split = PaymentSplit::firstOrFail();
        $this->assertSame([$factura->getMorphClass(), $factura->id, 'descuento_rol', 'ROL-2026-10'], [$split->pagable_type, $split->pagable_id, $split->tipo, $split->documento]);
        $this->assertEquals(50, $split->valor);

        // Sin caja ni bancos: Debe gastos y provisiones / Haber IESS, provisiones, CxC de la factura y el neto que se paga (350)
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 441.74, 0], ['5.2.02', 49.25, 0], ['5.2.03', 94.39, 0],
            ['2.1.04', 0, 90.99], ['2.1.05', 0, 94.39], ['1.1.03', 0, 50], ['2.1.07', 0, 350],
        ]);
        $this->chequeoEstricto()->assertExitCode(0);

        // La factura muestra el descuento en su estado de cuenta
        $doc = $this->getJson("/api/estado-cuenta/clientes/{$cliente->id}?company_id={$this->company->id}")->assertOk()->json('documentos.0');
        $this->assertEquals(0, $doc['saldo']);
        $this->assertSame('descuento_rol', $doc['cobros'][0]['forma']);
        $this->assertSame('Descuento en el rol de pagos', $doc['cobros'][0]['forma_label']);
        $this->assertEquals(50, $doc['cobros'][0]['monto']);
    }

    public function test_una_factura_de_900_mayor_al_neto_se_descuenta_parcial_y_el_resto_sigue_abierto(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(400, ['contact_id' => $cliente->id]);          // disponible 362.20
        $factura = $this->factura($cliente, 900);

        $rol = $this->generar()->assertOk();
        $linea = $this->linea($rol, $e);
        $this->assertEquals(362.20, $linea['consumos']);
        $this->assertEquals(0, $linea['neto']);
        $consumo = collect($linea['detalle'])->firstWhere('tipo', 'consumo');
        $this->assertEquals([900, 362.20], [$consumo['saldo'], $consumo['monto']]);

        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals(537.80, $factura->fresh()->saldo_pendiente);
        $this->assertEquals(362.20, InvoicePayment::firstOrFail()->monto);
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 400, 0], ['5.2.02', 44.60, 0], ['5.2.03', 89.17, 0],
            ['2.1.04', 0, 82.40], ['2.1.05', 0, 89.17], ['1.1.03', 0, 362.20],
        ]);
        $this->chequeoEstricto()->assertExitCode(0);

        // El saldo que queda se descuenta en el rol del mes siguiente
        $rol2 = $this->generar($this->anio, 11)->assertOk();
        $this->assertEquals(362.20, $this->linea($rol2, $e)['consumos']);
        $this->cerrar($rol2->json('id'))->assertOk();
        $this->assertEquals(175.60, $factura->fresh()->saldo_pendiente);
    }

    public function test_las_facturas_se_toman_de_la_mas_antigua_y_la_ultima_puede_quedar_parcial(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);       // disponible 400
        // Se crean fuera de orden: manda la fecha de emisión
        $nueva = $this->factura($cliente, 500, '2026-10-10');
        $vieja = $this->factura($cliente, 100, '2026-09-01');
        $media = $this->factura($cliente, 200, '2026-09-20');

        $linea = $this->linea($this->generar()->assertOk(), $e);
        $consumos = collect($linea['detalle'])->where('tipo', 'consumo')->values();
        $this->assertSame([$vieja->id, $media->id, $nueva->id], $consumos->pluck('invoice_id')->all());
        $this->assertEquals([100, 200, 100], $consumos->pluck('monto')->all());
        $this->assertEquals(400, $linea['consumos']);
        $this->assertEquals(0, $linea['neto']);

        $this->cerrar(Payroll::firstOrFail()->id)->assertOk();
        $this->assertEquals([0, 0, 400], [$vieja->fresh()->saldo_pendiente, $media->fresh()->saldo_pendiente, $nueva->fresh()->saldo_pendiente]);
        $this->assertSame(3, InvoicePayment::count());
        // Una sola línea de CxC con las tres referencias
        $cxc = $this->asientoDe(Payroll::firstOrFail())->lines()->whereHas('account', fn ($q) => $q->where('codigo', '1.1.03'))->firstOrFail();
        $this->assertEquals(400, $cxc->haber);
        foreach ([$vieja, $media, $nueva] as $f) {
            $this->assertStringContainsString($f->numero, (string) $cxc->referencia);
        }
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_se_puede_quitar_una_factura_antes_de_cerrar_y_el_rol_se_recalcula(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);
        $f1 = $this->factura($cliente, 50, '2026-10-01');
        $f2 = $this->factura($cliente, 30, '2026-10-05');
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 10])->assertCreated();
        $rol = $this->generar(null, null, [$e->id => ['horas_extra' => 0]])->assertOk();
        $this->assertEquals(80, $this->linea($rol, $e)['consumos']);

        $r = $this->postJson("/api/payrolls/{$rol->json('id')}/consumos", ['employee_id' => $e->id, 'excluir' => [$f1->id]])->assertOk();
        $linea = $this->linea($r, $e);
        $this->assertEquals(30, $linea['consumos']);
        $this->assertEquals(10, $linea['descuentos'], 'Lo demás de la línea se conserva');
        $this->assertEquals(360, $linea['neto']);
        $consumos = collect($linea['detalle'])->where('tipo', 'consumo')->keyBy('invoice_id');
        $this->assertFalse($consumos[$f1->id]['incluido']);
        $this->assertEquals(0, $consumos[$f1->id]['monto']);
        $this->assertTrue($consumos[$f2->id]['incluido']);
        $this->assertEquals(30, round($r->json('total_egresos') - 41.74 - 10, 2), 'Los totales del rol se recalculan');
        $this->assertEquals(360, $r->json('total_neto'));
        $this->assertSame(0, InvoicePayment::count(), 'Quitar o poner un consumo no cobra nada: solo cerrar el rol lo hace');
        $this->assertEquals([50, 30], [$f1->fresh()->saldo_pendiente, $f2->fresh()->saldo_pendiente]);

        $this->cerrar($rol->json('id'))->assertOk();
        $this->assertEquals([50, 0], [$f1->fresh()->saldo_pendiente, $f2->fresh()->saldo_pendiente]);
        $this->assertSame(1, InvoicePayment::count());

        // Un rol cerrado ya no se cambia
        $this->postJson("/api/payrolls/{$rol->json('id')}/consumos", ['employee_id' => $e->id, 'excluir' => []])->assertStatus(422);
        $this->assertEquals(50, $f1->fresh()->saldo_pendiente);
    }

    public function test_solo_entran_las_facturas_abiertas_del_cliente_ligado_y_de_esa_empresa(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(1000, ['contact_id' => $cliente->id]);
        $otroCliente = $this->cliente();
        $buena = $this->factura($cliente, 40, '2026-10-02');
        $this->factura($otroCliente, 999);                                              // de otro cliente
        $anulada = $this->factura($cliente, 70, '2026-10-03');
        $anulada->update(['estado' => 'anulado']);
        $cobrada = $this->factura($cliente, 80, '2026-10-04');
        $cobrada->update(['saldo_pendiente' => 0]);
        $this->factura($cliente, 90, '2026-11-02');                                      // de un mes que todavía no llega al rol de octubre
        $nd = $this->factura($cliente, 15, '2026-10-06');                                // una nota de débito no es un consumo
        $nd->update(['tipo_comprobante' => 'nota_debito_interna']);

        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $this->factura($cliente, 33, '2026-10-02', $otra);                               // mismo contacto id pero de otra empresa

        $linea = $this->linea($this->generar()->assertOk(), $e);
        $consumos = collect($linea['detalle'])->where('tipo', 'consumo');
        $this->assertSame([$buena->id], $consumos->pluck('invoice_id')->all());
        $this->assertEquals(40, $linea['consumos']);

        // El rol de noviembre sí trae la de noviembre (y el saldo que siga abierto)
        $nov = collect($this->linea($this->generar($this->anio, 11)->assertOk(), $e)['detalle'])->where('tipo', 'consumo');
        $this->assertCount(2, $nov);
    }

    public function test_un_empleado_sin_cliente_ligado_no_tiene_consumos(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(1000);
        $this->factura($cliente, 50);
        $linea = $this->linea($this->generar()->assertOk(), $e);
        $this->assertEquals(0, $linea['consumos']);
        $this->assertSame([], $linea['detalle']);
    }

    public function test_el_cliente_ligado_debe_ser_de_la_misma_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = $this->cliente([], $otra);
        $propio = $this->cliente();
        $datos = ['company_id' => $this->company->id, 'cedula' => '1700000099', 'nombres' => 'Nuevo Empleado', 'fecha_ingreso' => '2026-01-01', 'sueldo' => 500];

        $this->postJson('/api/employees', $datos + ['contact_id' => $ajeno->id])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $r = $this->postJson('/api/employees', $datos + ['contact_id' => $propio->id])->assertCreated();
        $this->assertSame($propio->id, $r->json('contact_id'));
        $this->assertSame($propio->razon_social, $r->json('contact.razon_social'));

        // Se puede cambiar o quitar
        $id = $r->json('id');
        $this->putJson("/api/employees/$id", $datos + ['contact_id' => null])->assertOk()->assertJsonPath('contact_id', null);
        $this->getJson('/api/employees?company_id='.$this->company->id)->assertOk()->assertJsonPath('0.contact', null);
    }

    public function test_el_consumo_de_un_cliente_relacionado_acredita_la_cxc_de_relacionadas(): void
    {
        $cliente = $this->cliente(['parte_relacionada' => true]);
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);
        $this->factura($cliente, 50);                                                    // nace en 1.1.09

        $this->cerrar($this->generar()->assertOk()->json('id'))->assertOk();
        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 441.74, 0], ['5.2.02', 49.25, 0], ['5.2.03', 94.39, 0],
            ['2.1.04', 0, 90.99], ['2.1.05', 0, 94.39], ['1.1.09', 0, 50], ['2.1.07', 0, 350],
        ]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_si_la_factura_se_cobra_entre_generar_y_cerrar_el_cierre_da_422_y_no_cambia_nada(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(441.74, ['contact_id' => $cliente->id]);
        $factura = $this->factura($cliente, 50);
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 10])->assertCreated();
        $rol = $this->generar()->assertOk();

        $factura->update(['saldo_pendiente' => 20]);                                     // alguien cobró parte mientras tanto
        $r = $this->cerrar($rol->json('id'))->assertStatus(422);
        $this->assertStringContainsString('ya no tiene saldo', $r->json('errors.payroll.0'));
        $this->assertSame('abierto', Payroll::firstOrFail()->estado);
        $this->assertEquals(20, $factura->fresh()->saldo_pendiente);
        $this->assertEquals(10, EmployeeMovement::firstOrFail()->saldo, 'Tampoco se aplicó el movimiento');
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(1, JournalEntry::count(), 'Solo el asiento de la venta');

        // Generado otra vez, descuenta lo que queda
        $this->assertEquals(20, $this->linea($this->generar()->assertOk(), $e)['consumos']);
    }

    // ------------------------------------------------------------------ todo junto

    public function test_un_rol_con_anticipo_prestamo_a_tres_cuotas_horas_extra_y_un_consumo_cuadra_al_centavo(): void
    {
        $cliente = $this->cliente();
        $e = $this->empleado(1000, ['contact_id' => $cliente->id]);
        $factura = $this->factura($cliente, 50);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo de quincena', 'monto' => 100, 'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo', 'monto' => 100, 'cuotas' => 3, 'entrega' => ['forma_pago' => 'efectivo']])->assertCreated();
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'horas_extra', 'concepto' => '8 horas extra', 'monto' => 40])->assertCreated();
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa', 'monto' => 10])->assertCreated();

        $rol = $this->generar()->assertOk();
        $l = $this->linea($rol, $e);
        $this->assertEquals(
            ['sueldo' => 1000, 'horas_extra' => 40, 'aporte_personal' => 98.28, 'anticipos' => 100, 'prestamos' => 33.33, 'descuentos' => 10,
                'consumos' => 50, 'neto' => 748.39, 'aporte_patronal' => 115.96, 'decimo_tercero' => 86.63, 'decimo_cuarto' => 39.17, 'vacaciones' => 43.37],
            collect($l)->only(['sueldo', 'horas_extra', 'aporte_personal', 'anticipos', 'prestamos', 'descuentos', 'consumos', 'neto',
                'aporte_patronal', 'decimo_tercero', 'decimo_cuarto', 'vacaciones'])->all(),
        );
        $this->assertEquals(1040, $rol->json('total_ingresos'));
        $this->assertEquals(291.61, $rol->json('total_egresos'));       // 98.28 + 100 + 33.33 + 10 + 50
        $this->assertEquals(748.39, $rol->json('total_neto'));
        $this->assertEquals(285.13, $rol->json('total_provisiones'));      // patronal 115.96 + décimos, fondos y vacaciones 169.17

        $this->cerrar($rol->json('id'))->assertOk();

        $this->assertLineas($this->asientoDe(Payroll::firstOrFail()), [
            ['5.2.01', 1040, 0], ['5.2.02', 115.96, 0], ['5.2.03', 169.17, 0],
            ['2.1.04', 0, 214.24], ['2.1.05', 0, 169.17],
            ['1.1.11', 0, 133.33],            // anticipo 100 + cuota 33.33, dinero que la empresa entregó
            ['2.1.06', 0, 10],                // la multa
            ['1.1.03', 0, 50],                // la factura del consumo
            ['2.1.07', 0, 748.39],            // lo que se paga
        ]);
        $this->assertEquals(0, $factura->fresh()->saldo_pendiente);
        $this->assertEquals(
            ['anticipo' => [0, 'aplicado'], 'prestamo' => [66.67, 'pendiente'], 'ingreso_extra' => [0, 'aplicado'], 'descuento' => [0, 'aplicado']],
            EmployeeMovement::all()->mapWithKeys(fn ($m) => [$m->tipo => [(float) $m->saldo, $m->estado]])->all(),
        );
        $this->chequeoEstricto()->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }
}
