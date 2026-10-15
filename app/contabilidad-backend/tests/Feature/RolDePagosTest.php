<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Payroll;
use App\Support\RolDePagosPdf;

/**
 * T7.1 / N4: rol de pagos individual (PDF) y rol general (PDF y CSV) con lo que de verdad entró en la línea de cada empleado.
 */
class RolDePagosTest extends NominaTestCase
{
    /** Un rol con anticipo, préstamo, horas extra, multa y un consumo; devuelve [rol, empleado]. */
    private function rolCompleto(): array
    {
        $cliente = $this->cliente();
        $e = $this->empleado(1000, ['contact_id' => $cliente->id, 'nombres' => 'María Fernanda Pérez']);
        $this->factura($cliente, 50);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo de quincena', 'monto' => 100])->assertCreated();
        $this->movimiento($e, ['tipo' => 'prestamo', 'concepto' => 'Préstamo de emergencia', 'monto' => 100, 'cuotas' => 3])->assertCreated();
        $this->movimiento($e, ['tipo' => 'ingreso_extra', 'subtipo' => 'horas_extra', 'concepto' => '8 horas extra de septiembre', 'monto' => 40])->assertCreated();
        $this->movimiento($e, ['tipo' => 'descuento', 'concepto' => 'Multa de tránsito', 'monto' => 10])->assertCreated();

        return [$this->generar()->assertOk(), $e];
    }

    private function textoDelPdf(string $contenido): ?string
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            return null;
        }
        $ruta = tempnam(sys_get_temp_dir(), 'rol').'.pdf';
        file_put_contents($ruta, $contenido);
        try {
            return (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
        } finally {
            @unlink($ruta);
        }
    }

    public function test_el_rol_individual_lleva_ingresos_egresos_movimientos_consumos_y_el_neto_en_letras(): void
    {
        [$rol, $e] = $this->rolCompleto();
        $id = $rol->json('id');

        $r = $this->get("/api/payrolls/$id/rol/{$e->id}?company_id={$this->company->id}")->assertOk();
        $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('Rol-2026-10-'.$e->cedula.'.pdf', (string) $r->headers->get('content-disposition'));

        $html = RolDePagosPdf::html(Payroll::findOrFail($id), Payroll::findOrFail($id)->lines()->with('employee')->firstOrFail(), 'Contadora Uno');
        foreach (['ROL DE PAGOS INDIVIDUAL', 'Octubre 2026', 'Empresa Nómina Test SA', '1791234567001', 'María Fernanda Pérez', $e->cedula, 'Técnico',
            'Sueldo', '$ 1,000.00', 'Horas extra · 8 horas extra de septiembre', '$ 40.00', 'Total ingresos', '$ 1,040.00',
            'Aporte personal IESS 9,45%', '$ 98.28', 'Anticipo · Anticipo de quincena', '$ 100.00',
            'Préstamo · Préstamo de emergencia (Cuota 1 de 3)', '$ 33.33', 'Descuento · Multa de tránsito', '$ 10.00',
            'Consumo de la empresa - factura 001-001-000000001', '$ 50.00', 'Total egresos', '$ 291.61',
            'Neto a recibir', '$ 748.39', 'SETECIENTOS CUARENTA Y OCHO CON 39/100 DÓLARES', 'Empleador', 'Contadora Uno', 'Recibí conforme'] as $texto) {
            $this->assertStringContainsString(str_replace('·', '-', $texto), $html, "Falta «{$texto}» en el rol");
        }

        if (($texto = $this->textoDelPdf($r->getContent())) !== null) {
            foreach (['María Fernanda Pérez', 'Octubre 2026', '748.39', 'SETECIENTOS CUARENTA Y OCHO CON 39/100 DÓLARES', 'Cuota 1 de 3', 'Multa de tránsito'] as $t) {
                $this->assertStringContainsString($t, $texto, "El PDF real no dice «{$t}»");
            }
        }

        // Cerrado, sigue igual de completo
        $this->cerrar($id)->assertOk();
        $this->get("/api/payrolls/$id/rol/{$e->id}?company_id={$this->company->id}")->assertOk();
    }

    public function test_el_rol_individual_avisa_lo_que_se_difiere_al_mes_siguiente_y_un_rol_viejo_se_imprime(): void
    {
        $e = $this->empleado(400, ['nombres' => 'Luis Mora']);
        $this->movimiento($e, ['tipo' => 'anticipo', 'concepto' => 'Anticipo grande', 'monto' => 500])->assertCreated();
        $rol = $this->generar()->assertOk();
        $payroll = Payroll::findOrFail($rol->json('id'));
        $html = RolDePagosPdf::html($payroll, $payroll->lines()->with('employee')->firstOrFail());
        $this->assertStringContainsString('$ 137.80 de descuentos quedan pendientes', $html);
        $this->assertStringContainsString('CERO CON 00/100 DÓLARES', $html);

        // Una línea de antes de T7.1 (sin detalle, solo columnas) también sale: una fila por rubro
        $linea = $payroll->lines()->firstOrFail();
        $linea->update(['detalle' => null, 'horas_extra' => 10, 'prestamos' => 5]);
        $html = RolDePagosPdf::html($payroll, $linea->fresh('employee'));
        $this->assertStringContainsString('Horas extra', $html);
        $this->assertStringContainsString('Préstamo', $html);
    }

    public function test_el_rol_general_es_una_tabla_con_todos_los_empleados_en_csv_y_pdf(): void
    {
        [$rol, $uno] = $this->rolCompleto();
        $dos = $this->empleado(800, ['nombres' => 'Ana Zambrano']);
        $rol = $this->generar()->assertOk();
        $id = $rol->json('id');
        $c = $this->company->id;

        $csv = $this->get("/api/payrolls/$id/rol-general?company_id=$c&formato=csv")->assertOk();
        $this->assertStringContainsString('Rol-general-2026-10.csv', (string) $csv->headers->get('content-disposition'));
        $texto = $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $texto);
        $filas = array_map('str_getcsv', array_filter(explode("\n", substr($texto, 3))));
        $this->assertSame(['Empleado', 'Cédula', 'Sueldo', 'H. extra', 'Comisiones', 'Otros ingr.', 'Total ingresos', 'IESS 9.45%', 'Permisos',
            'Anticipos', 'Préstamos', 'Descuentos', 'Consumos', 'Total egresos', 'Neto a pagar'], $filas[0]);
        $this->assertCount(4, $filas, 'Encabezado, dos empleados y el total');
        // Ordenados por nombre: Ana Zambrano y luego María
        $this->assertSame('Ana Zambrano', $filas[1][0]);
        $this->assertSame(['800.00', '0.00', '0.00', '0.00', '800.00', '75.60', '0.00', '0.00', '0.00', '0.00', '0.00', '75.60', '724.40'], array_slice($filas[1], 2));
        $this->assertSame('María Fernanda Pérez', $filas[2][0]);
        $this->assertSame(['1000.00', '40.00', '0.00', '0.00', '1040.00', '98.28', '0.00', '100.00', '33.33', '10.00', '50.00', '291.61', '748.39'], array_slice($filas[2], 2));
        $this->assertSame(['TOTAL', '', '1800.00', '40.00', '0.00', '0.00', '1840.00', '173.88', '0.00', '100.00', '33.33', '10.00', '50.00', '367.21', '1472.79'], $filas[3]);

        $pdf = $this->get("/api/payrolls/$id/rol-general?company_id=$c")->assertOk();
        $this->assertStringStartsWith('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if (($t = $this->textoDelPdf($pdf->getContent())) !== null) {
            foreach (['ROL DE PAGOS GENERAL', 'OCTUBRE 2026', 'Ana Zambrano', '748.39', '1472.79'] as $texto) {
                $this->assertStringContainsString($texto, $t);
            }
        }
        $this->get("/api/payrolls/$id/rol-general?company_id=$c&formato=xml")->assertStatus(422);
    }

    public function test_un_rol_de_otra_empresa_o_un_empleado_que_no_esta_en_el_rol_no_existen(): void
    {
        [$rol, $e] = $this->rolCompleto();
        $id = $rol->json('id');
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = $this->empleado(500, [], $otra);
        $fuera = $this->empleado(500);                                                  // creado después de generar: no está en el rol

        $this->get("/api/payrolls/$id/rol/{$e->id}?company_id={$otra->id}")->assertNotFound();
        $this->get("/api/payrolls/$id/rol-general?company_id={$otra->id}")->assertNotFound();
        $this->get("/api/payrolls/$id/rol/{$ajeno->id}?company_id={$this->company->id}")->assertNotFound();
        $this->get("/api/payrolls/$id/rol/{$fuera->id}?company_id={$this->company->id}")->assertNotFound();
        $this->getJson("/api/payrolls/$id/rol/{$e->id}")->assertStatus(422);           // sin empresa
        $this->getJson("/api/payrolls/$id/rol-general")->assertStatus(422);
    }
}
