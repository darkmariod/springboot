<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SriDocument;
use App\Models\User;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T4.3b: estado de situación financiera y estado de resultados por período, con el detalle por cuenta.
 * Cuentan los mismos asientos que los reportes de siempre (todo lo que no es borrador); el asiento de cierre del
 * ejercicio no entra al estado de resultados del año que cierra, pero sí al balance (ahí mueve el resultado a patrimonio).
 */
class EstadosFinancierosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Estados SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->otra = Company::create([
            'ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        Cuentas::sembrar($this->otra->id);
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));
    }

    /** Ejercicio 2025: aporte 1000, ventas 600 + 400, gasto 150, compra a crédito 200 (ingresos 1000, gastos 350, resultado 650). */
    private function ejercicio2025(): void
    {
        $this->asiento('Aporte de capital', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->asiento('Venta de contado', '2025-02-05', [['1.1.01', 600, 0], ['4.1.01', 0, 600]]);
        $this->asiento('Venta a crédito', '2025-03-10', [['1.1.03', 400, 0], ['4.1.01', 0, 400]]);
        $this->asiento('Gasto general', '2025-04-15', [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->asiento('Compra a crédito', '2025-05-20', [['5.1.01', 200, 0], ['2.1.01', 0, 200]]);
    }

    // ------------------------------------------------------------- estado de resultados

    public function test_el_estado_de_resultados_arma_el_arbol_por_codigo_con_saldos_y_subtotales(): void
    {
        $this->ejercicio2025();

        $res = $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        $res->assertJsonPath('company_id', $this->company->id)->assertJsonPath('desde', '2025-01-01')->assertJsonPath('hasta', '2025-12-31');
        $this->assertEquals(1000, $res->json('totales.ingresos'));
        $this->assertEquals(350, $res->json('totales.gastos'));
        $this->assertEquals(650, $res->json('totales.resultado'));

        $secciones = collect($res->json('secciones'))->keyBy('tipo');
        $this->assertSame(['ingreso', 'gasto'], collect($res->json('secciones'))->pluck('tipo')->all());
        $this->assertSame('Ingresos', $secciones['ingreso']['titulo']);
        $this->assertEquals(1000, $secciones['ingreso']['total']);

        // Ingresos: 4 INGRESOS → 4.1.01 Ventas (la cuenta 4.1.01 cuelga del encabezado 4: no existe un 4.1)
        $raiz = $secciones['ingreso']['cuentas'];
        $this->assertCount(1, $raiz);
        $this->assertSame(['4', 'INGRESOS', 1], [$raiz[0]['codigo'], $raiz[0]['nombre'], $raiz[0]['nivel']]);
        $this->assertEquals(1000, $raiz[0]['saldo']);
        $this->assertTrue($raiz[0]['es_encabezado']);
        $this->assertCount(1, $raiz[0]['hijos']);
        $ventas = $raiz[0]['hijos'][0];
        $this->assertSame(['4.1.01', 'Ventas', 3], [$ventas['codigo'], $ventas['nombre'], $ventas['nivel']]);
        $this->assertEquals(0, $ventas['debe']);
        $this->assertEquals(1000, $ventas['haber']);
        $this->assertEquals(1000, $ventas['saldo']);
        $this->assertFalse($ventas['es_encabezado']);
        $this->assertSame([], $ventas['hijos']);

        // Gastos: 5 GASTOS (350) → 5.1.01 Compras 200 y 5.1.02 Gastos generales 150; las cuentas sin movimiento no salen
        $gastos = $secciones['gasto']['cuentas'];
        $this->assertSame('5', $gastos[0]['codigo']);
        $this->assertEquals(350, $gastos[0]['saldo']);
        $this->assertSame(['5.1.01', '5.1.02'], array_column($gastos[0]['hijos'], 'codigo'));
        $this->assertEquals([200, 150], array_column($gastos[0]['hijos'], 'saldo'));
        $this->assertEquals([200, 0], [$gastos[0]['hijos'][0]['debe'], $gastos[0]['hijos'][0]['haber']]);
    }

    public function test_el_estado_de_resultados_respeta_el_periodo_elegido(): void
    {
        $this->ejercicio2025();
        $this->asiento('Venta del año siguiente', '2026-01-15', [['1.1.01', 100, 0], ['4.1.01', 0, 100]]);

        $feb = $this->resultados('2025-02-01', '2025-03-31')->assertOk();
        $this->assertEquals([1000, 0, 1000], [$feb->json('totales.ingresos'), $feb->json('totales.gastos'), $feb->json('totales.resultado')]);

        $abril = $this->resultados('2025-04-01', '2025-04-30')->assertOk();
        $this->assertEquals([0, 150, -150], [$abril->json('totales.ingresos'), $abril->json('totales.gastos'), $abril->json('totales.resultado')]);
        $this->assertSame(['5.1.02'], array_column($abril->json('secciones.1.cuentas.0.hijos'), 'codigo'));
        $this->assertSame([], $abril->json('secciones.0.cuentas'), 'Sin ingresos en abril la sección queda vacía');

        // Los extremos cuentan (el 31/12 y el 01/01)
        $this->asiento('Último día', '2025-12-31', [['1.1.01', 5, 0], ['4.1.01', 0, 5]]);
        $anio = $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        $this->assertEquals(1005, $anio->json('totales.ingresos'));
        $enero = $this->resultados('2026-01-01', '2026-01-31')->assertOk();
        $this->assertEquals(100, $enero->json('totales.ingresos'));

        // Sin fechas: todo
        $todo = $this->getJson("/api/accounting/estado-resultados?company_id={$this->company->id}")->assertOk();
        $this->assertEquals(1105, $todo->json('totales.ingresos'));
    }

    public function test_cuentan_pendientes_y_mayorizados_pero_no_los_borradores(): void
    {
        $this->ejercicio2025();
        $this->asiento('Borrador', '2025-06-01', [['5.1.02', 999, 0], ['1.1.01', 0, 999]], 'borrador');
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'mes' => 2, 'anio' => 2025])->assertOk()->assertJsonPath('mayorizados', 1);

        $res = $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        $this->assertEquals(350, $res->json('totales.gastos'), 'El borrador de 999 no cuenta');
        $this->assertEquals(1000, $res->json('totales.ingresos'), 'Cuentan la venta mayorizada y la pendiente');
    }

    public function test_una_cuenta_de_gasto_con_codigo_de_ingresos_sale_en_gastos(): void
    {
        $this->asiento('Venta', '2025-02-05', [['1.1.01', 500, 0], ['4.1.01', 0, 500]]);
        // 4.1.02 "Devoluciones y descuentos en ventas" es tipo gasto aunque su código cuelgue de 4 INGRESOS
        $this->asiento('Devolución', '2025-02-10', [['4.1.02', 50, 0], ['1.1.01', 0, 50]]);

        $res = $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        $this->assertEquals([500, 50, 450], [$res->json('totales.ingresos'), $res->json('totales.gastos'), $res->json('totales.resultado')]);
        $this->assertSame(['4'], array_column($res->json('secciones.0.cuentas'), 'codigo'));
        $this->assertEquals(500, $res->json('secciones.0.cuentas.0.saldo'), 'Su subtotal no incluye la devolución');
        $this->assertSame(['4.1.02'], array_column($res->json('secciones.1.cuentas'), 'codigo'));
        $this->assertEquals(50, $res->json('secciones.1.cuentas.0.saldo'));
    }

    // ------------------------------------------------------------- balance general

    public function test_el_balance_general_arma_el_arbol_y_cumple_la_ecuacion_contable(): void
    {
        $this->ejercicio2025();

        $res = $this->balance('2025-12-31')->assertOk()->assertJsonPath('hasta', '2025-12-31');
        $secciones = collect($res->json('secciones'))->keyBy('tipo');
        $this->assertSame(['activo', 'pasivo', 'patrimonio'], collect($res->json('secciones'))->pluck('tipo')->all());

        // 1 ACTIVO 1850 → 1.1 ACTIVO CORRIENTE 1850 → 1.1.01 Caja 1450 y 1.1.03 CxC 400
        $activo = $secciones['activo']['cuentas'][0];
        $this->assertSame(['1', 'ACTIVO'], [$activo['codigo'], $activo['nombre']]);
        $this->assertEquals(1850, $activo['saldo']);
        $this->assertSame('1.1', $activo['hijos'][0]['codigo']);
        $this->assertEquals(1850, $activo['hijos'][0]['saldo']);
        $this->assertSame(['1.1.01', '1.1.03'], array_column($activo['hijos'][0]['hijos'], 'codigo'));
        $this->assertEquals([1450, 400], array_column($activo['hijos'][0]['hijos'], 'saldo'));
        $this->assertEquals([1000 + 600, 150], [$activo['hijos'][0]['hijos'][0]['debe'], $activo['hijos'][0]['hijos'][0]['haber']]);

        // Pasivo y patrimonio con su signo natural (haber − debe)
        $this->assertEquals(200, $secciones['pasivo']['total']);
        $this->assertSame('2.1.01', $secciones['pasivo']['cuentas'][0]['hijos'][0]['hijos'][0]['codigo']);
        $this->assertEquals(1000, $secciones['patrimonio']['total']);
        $this->assertSame('3.1.01', $secciones['patrimonio']['cuentas'][0]['hijos'][0]['codigo']);

        // Ecuación: Activo = Pasivo + Patrimonio + Resultado del período
        $this->assertEquals(650, $res->json('resultado_periodo'));
        $this->assertEquals(1850, $res->json('totales.activo'));
        $this->assertEquals(200, $res->json('totales.pasivo'));
        $this->assertEquals(1000, $res->json('totales.patrimonio'));
        $this->assertEquals(650, $res->json('totales.resultado_periodo'));
        $this->assertEquals(1850, $res->json('totales.pasivo_patrimonio_resultado'));
        $this->assertEquals(0, $res->json('totales.diferencia'));
        $this->assertTrue($res->json('totales.cuadra'));
    }

    public function test_el_balance_general_a_una_fecha_anterior_solo_cuenta_hasta_esa_fecha(): void
    {
        $this->ejercicio2025();

        $res = $this->balance('2025-02-28')->assertOk();
        $this->assertEquals(1600, $res->json('totales.activo'));      // 1000 + 600 en Caja
        $this->assertEquals(0, $res->json('totales.pasivo'));
        $this->assertEquals(1000, $res->json('totales.patrimonio'));
        $this->assertEquals(600, $res->json('totales.resultado_periodo'));
        $this->assertTrue($res->json('totales.cuadra'));

        // Sin fecha: hasta hoy (todo lo registrado)
        $todo = $this->getJson("/api/accounting/balance-general?company_id={$this->company->id}")->assertOk();
        $this->assertEquals(1850, $todo->json('totales.activo'));
        $this->assertTrue($todo->json('totales.cuadra'));
    }

    public function test_una_diferencia_se_informa_en_lugar_de_esconderse(): void
    {
        $this->ejercicio2025();
        // Un asiento alterado por fuera del sistema (líneas que no cuadran) rompe la ecuación y el balance lo dice
        $e = JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-009999', 'fecha' => '2025-06-10',
            'concepto' => 'Alterado', 'total_debe' => 10, 'total_haber' => 10, 'estado' => 'pendiente']);
        $e->lines()->create(['account_id' => Account::where('company_id', $this->company->id)->where('codigo', '1.1.01')->value('id'), 'debe' => 10, 'haber' => 0]);

        $res = $this->balance('2025-12-31')->assertOk();
        $this->assertFalse($res->json('totales.cuadra'));
        $this->assertEquals(10, $res->json('totales.diferencia'));
    }

    // ------------------------------------------------------------- el asiento de cierre

    public function test_el_asiento_de_cierre_no_entra_al_estado_de_resultados_de_su_ejercicio_pero_si_al_balance(): void
    {
        $this->ejercicio2025();
        foreach (range(1, 12) as $mes) {
            $this->postJson('/api/accounting/periodos/cerrar', ['company_id' => $this->company->id, 'anio' => 2025, 'mes' => $mes])->assertOk();
        }
        $this->postJson('/api/accounting/cierre-ejercicio', ['company_id' => $this->company->id, 'anio' => 2025])->assertOk();

        // El estado de resultados de 2025 sigue mostrando lo que se vendió y se gastó (no queda en cero)
        $res = $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        $this->assertEquals([1000, 350, 650], [$res->json('totales.ingresos'), $res->json('totales.gastos'), $res->json('totales.resultado')]);
        $this->assertEquals(1000, $res->json('secciones.0.cuentas.0.hijos.0.saldo'));
        // También si el rango es solo diciembre: nada del cierre
        $dic = $this->resultados('2025-12-01', '2025-12-31')->assertOk();
        $this->assertEquals([0, 0, 0], [$dic->json('totales.ingresos'), $dic->json('totales.gastos'), $dic->json('totales.resultado')]);

        // El balance al 31/12 sí lo cuenta: el resultado ya está en patrimonio (3.1.02) y el "resultado del período" queda en cero
        $bal = $this->balance('2025-12-31')->assertOk();
        $this->assertEquals(0, $bal->json('totales.resultado_periodo'));
        $this->assertEquals(1650, $bal->json('totales.patrimonio'));
        $patrimonio = collect($bal->json('secciones'))->firstWhere('tipo', 'patrimonio');
        $this->assertSame(['3.1.01', '3.1.02'], array_column($patrimonio['cuentas'][0]['hijos'], 'codigo'));
        $this->assertEquals([1000, 650], array_column($patrimonio['cuentas'][0]['hijos'], 'saldo'));
        $this->assertEquals(1850, $bal->json('totales.activo'));
        $this->assertTrue($bal->json('totales.cuadra'));

        // Antes del cierre (30/11) el balance muestra el resultado como "del período" y todavía no hay 3.1.02
        $nov = $this->balance('2025-11-30')->assertOk();
        $this->assertEquals(650, $nov->json('totales.resultado_periodo'));
        $this->assertEquals(1000, $nov->json('totales.patrimonio'));
        $this->assertTrue($nov->json('totales.cuadra'));

        // Un año después: el ejercicio cerrado ya no mezcla su resultado con el del año nuevo
        $this->asiento('Venta 2026', '2026-02-02', [['1.1.01', 100, 0], ['4.1.01', 0, 100]]);
        $sig = $this->balance('2026-03-31')->assertOk();
        $this->assertEquals(100, $sig->json('totales.resultado_periodo'));
        $this->assertEquals(1650, $sig->json('totales.patrimonio'));
        $this->assertEquals(1950, $sig->json('totales.activo'));
        $this->assertTrue($sig->json('totales.cuadra'));

        // Los reportes de siempre también excluyen el cierre del resultado
        $this->getJson("/api/income-statement?company_id={$this->company->id}")->assertOk()
            ->assertJsonPath('ingresos', 1100)->assertJsonPath('gastos', 350)->assertJsonPath('utilidad_ejercicio', 750);
        $this->getJson("/api/balance-sheet?company_id={$this->company->id}")->assertOk()
            ->assertJsonPath('activos', 1950)->assertJsonPath('cuadrado', true);
    }

    // ------------------------------------------------------------- exportar

    public function test_se_exporta_a_excel_con_el_detalle_y_el_resultado(): void
    {
        $this->ejercicio2025();

        $csv = $this->get("/api/accounting/balance-general?company_id={$this->company->id}&hasta=2025-12-31&formato=excel")->assertOk();
        $this->assertStringContainsString('balance-general', (string) $csv->headers->get('content-disposition'));
        $this->assertStringContainsString('.csv', (string) $csv->headers->get('content-disposition'));
        $filas = $this->filasCsv($csv->getContent());
        $this->assertSame(['Código', 'Cuenta', 'Saldo'], $filas[0]);
        $caja = $this->fila($filas, '1.1.01');
        $this->assertSame(['Caja', '1450.00'], [trim($caja[1]), $caja[2]]);
        $this->assertSame('1850.00', $this->filaPorNombre($filas, 'TOTAL ACTIVO')[2]);
        $this->assertSame('650.00', $this->filaPorNombre($filas, 'Resultado del período')[2]);
        $this->assertSame('1850.00', $this->filaPorNombre($filas, 'TOTAL PASIVO + PATRIMONIO + RESULTADO')[2]);
        $this->assertSame('Cuadra', $this->filaPorNombre($filas, 'Verificación')[2]);

        $csv = $this->get("/api/accounting/estado-resultados?company_id={$this->company->id}&desde=2025-01-01&hasta=2025-12-31&formato=excel")->assertOk();
        $this->assertStringContainsString('estado-resultados', (string) $csv->headers->get('content-disposition'));
        $filas = $this->filasCsv($csv->getContent());
        $this->assertSame('1000.00', $this->fila($filas, '4.1.01')[2]);
        $this->assertSame('150.00', $this->fila($filas, '5.1.02')[2]);
        $this->assertSame('650.00', $this->filaPorNombre($filas, 'RESULTADO DEL PERÍODO')[2]);
    }

    public function test_el_pdf_trae_los_numeros_y_no_imprime_signos_de_interrogacion_con_una_perdida(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext no está instalado: no se puede leer el PDF.');
        }
        $this->asiento('Aporte', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->asiento('Gasto', '2025-04-15', [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);

        $casos = [
            ["/api/accounting/estado-resultados?company_id={$this->company->id}&desde=2025-01-01&hasta=2025-12-31&formato=pdf", ['Gastos generales', '150.00', '-150.00']],
            ["/api/accounting/balance-general?company_id={$this->company->id}&hasta=2025-12-31&formato=pdf", ['Caja', '850.00', '-150.00', 'Cuadra']],
        ];
        foreach ($casos as [$url, $esperados]) {
            $res = $this->get($url)->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'));
            $ruta = tempnam(sys_get_temp_dir(), 'ef').'.pdf';
            file_put_contents($ruta, $res->getContent());
            try {
                $texto = (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
            } finally {
                @unlink($ruta);
            }
            $plano = preg_replace('/\s+/', ' ', $texto);
            foreach ($esperados as $e) {
                $this->assertStringContainsString($e, $plano, "El PDF debe decir «{$e}»");
            }
            $this->assertStringNotContainsString('?', $texto, 'Ningún carácter se imprime como signo de interrogación');
            $this->assertStringNotContainsString('−', $texto);
        }
    }

    // ------------------------------------------------------------- validaciones, permisos, empresas

    public function test_validaciones_permisos_y_aislamiento_entre_empresas(): void
    {
        $this->ejercicio2025();

        $this->getJson('/api/accounting/estado-resultados')->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->getJson('/api/accounting/balance-general?company_id=99999')->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->resultados('2025-12-31', '2025-01-01')->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->getJson("/api/accounting/estado-resultados?company_id={$this->company->id}&desde=ayer")->assertStatus(422)->assertJsonValidationErrors('desde');
        $this->getJson("/api/accounting/balance-general?company_id={$this->company->id}&formato=xml")->assertStatus(422)->assertJsonValidationErrors('formato');

        // Otra empresa no ve nada de esta
        $otra = $this->getJson("/api/accounting/estado-resultados?company_id={$this->otra->id}&desde=2025-01-01&hasta=2025-12-31")->assertOk();
        $this->assertEquals([0, 0, 0], [$otra->json('totales.ingresos'), $otra->json('totales.gastos'), $otra->json('totales.resultado')]);
        $this->assertTrue($this->getJson("/api/accounting/balance-general?company_id={$this->otra->id}")->assertOk()->json('totales.cuadra'));

        // El contador ve; el cajero no
        Sanctum::actingAs(User::factory()->create(['rol' => 'contador', 'activo' => true]));
        $this->resultados('2025-01-01', '2025-12-31')->assertOk();
        Sanctum::actingAs(User::factory()->create(['rol' => 'cajero', 'activo' => true]));
        $this->resultados('2025-01-01', '2025-12-31')->assertStatus(403);
        $this->balance('2025-12-31')->assertStatus(403);
    }

    // ------------------------------------------------------------- helpers

    private function resultados(string $desde, string $hasta)
    {
        return $this->getJson("/api/accounting/estado-resultados?company_id={$this->company->id}&desde={$desde}&hasta={$hasta}");
    }

    private function balance(string $hasta)
    {
        return $this->getJson("/api/accounting/balance-general?company_id={$this->company->id}&hasta={$hasta}");
    }

    /** @param  array<int,array{0:string,1:float|int,2:float|int}>  $filas [código de cuenta, debe, haber] */
    private function asiento(string $concepto, string $fecha, array $filas, string $estado = 'pendiente'): int
    {
        $lineas = array_map(fn ($f) => [
            'account_id' => Account::where('company_id', $this->company->id)->where('codigo', $f[0])->firstOrFail()->id,
            'debe' => $f[1], 'haber' => $f[2],
        ], $filas);

        return $this->postJson('/api/journal-entries', ['company_id' => $this->company->id, 'fecha' => $fecha, 'concepto' => $concepto,
            'estado' => $estado, 'lines' => $lineas])->assertCreated()->json('id');
    }

    /** @return array<int,array<int,string>> */
    private function filasCsv(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_values(array_filter(explode("\n", $csv), fn ($l) => $l !== '')));
    }

    private function fila(array $filas, string $codigo): array
    {
        foreach ($filas as $f) {
            if (($f[0] ?? null) === $codigo) {
                return $f;
            }
        }
        $this->fail("No hay una fila con el código $codigo");
    }

    private function filaPorNombre(array $filas, string $nombre): array
    {
        foreach ($filas as $f) {
            if (trim($f[1] ?? '') === $nombre) {
                return $f;
            }
        }
        $this->fail("No hay una fila llamada «$nombre»");
    }
}
