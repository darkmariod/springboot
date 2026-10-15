<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\ComprobanteCaja;
use App\Support\Cuentas;
use App\Support\MontoEnLetras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.3 / C1: cada ingreso o egreso de caja hecho a mano lleva su número (CI-/CE-, por empresa), su asiento con la
 * contrapartida elegida y su comprobante en PDF con el monto en letras.
 */
class ComprobantesCajaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CashSession $sesion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Caja Test SA', 'dir_matriz' => 'Av. Caja 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        Sanctum::actingAs(User::factory()->create());
        $this->sesion = $this->abrirCaja($this->company, 100);
    }

    public function test_un_ingreso_de_caja_tiene_numero_y_asiento_debe_caja_haber_la_contrapartida(): void
    {
        $r = $this->movimiento('ingreso', 120.50, 'Aporte del socio', 'capital')->assertOk();

        $mov = CashMovement::firstOrFail();
        $this->assertSame('CI-000001', $mov->numero);
        $this->assertSame('CI-000001', $r->json('movimiento.numero'));
        $this->assertSame($this->company->id, (int) $mov->company_id);
        $this->assertEquals(120.50, (float) $mov->monto);
        $this->assertSame(Cuentas::cuenta($this->company->id, 'capital')->id, (int) $mov->cuenta_contrapartida_id);

        $asiento = $this->asientoDe($mov);
        $this->assertSame(now()->toDateString(), $asiento->fecha->toDateString());
        $this->assertStringContainsString('CI-000001', $asiento->concepto);
        $this->assertEquals(120.50, (float) $asiento->total_debe);
        $this->assertEquals(120.50, (float) $asiento->total_haber);
        $this->assertLineas($asiento, [['1.1.01', 120.50, 0], ['3.1.01', 0, 120.50]]);

        // La sesión sigue sumando el ingreso
        $this->assertEquals(120.50, (float) $this->sesion->fresh()->ingresos);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_egreso_de_caja_tiene_numero_propio_y_asiento_debe_la_contrapartida_haber_caja(): void
    {
        $this->movimiento('ingreso', 300, 'Fondo', 'capital')->assertOk();
        $this->movimiento('egreso', 45.25, 'Papelería', 'gastos_generales')->assertOk();
        $this->movimiento('egreso', 10, 'Movilización', 'gastos_generales')->assertOk();
        $this->movimiento('ingreso', 5, 'Otro aporte', 'capital')->assertOk();

        // Los ingresos y los egresos llevan cuentas de números separadas
        $this->assertSame(['CI-000001', 'CI-000002'], CashMovement::where('tipo', 'ingreso')->orderBy('id')->pluck('numero')->all());
        $this->assertSame(['CE-000001', 'CE-000002'], CashMovement::where('tipo', 'egreso')->orderBy('id')->pluck('numero')->all());

        $egreso = CashMovement::where('numero', 'CE-000001')->firstOrFail();
        $asiento = $this->asientoDe($egreso);
        $this->assertLineas($asiento, [['5.1.02', 45.25, 0], ['1.1.01', 0, 45.25]]);
        $this->assertEquals(55.25, (float) $this->sesion->fresh()->egresos);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_cada_empresa_lleva_sus_propios_numeros_y_el_secuencial_de_facturas_no_se_toca(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        Cuentas::sembrar($otra->id);
        $sesionOtra = $this->abrirCaja($otra, 0);
        $secuencial = (int) $this->company->fresh()->secuencial;

        $this->movimiento('ingreso', 10, 'A', 'capital')->assertOk();
        $this->movimiento('ingreso', 10, 'B', 'capital')->assertOk();
        $this->postJson("/api/cash/{$sesionOtra->id}/movements", [
            'tipo' => 'ingreso', 'monto' => 7, 'concepto' => 'C', 'cuenta_contrapartida_id' => Cuentas::cuenta($otra->id, 'capital')->id,
        ])->assertOk();

        $this->assertSame(['CI-000001', 'CI-000002'], CashMovement::where('company_id', $this->company->id)->orderBy('id')->pluck('numero')->all());
        $this->assertSame(['CI-000001'], CashMovement::where('company_id', $otra->id)->pluck('numero')->all());
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial);
    }

    public function test_la_contrapartida_es_obligatoria_y_sin_ella_no_se_crea_nada(): void
    {
        $this->postJson("/api/cash/{$this->sesion->id}/movements", ['tipo' => 'ingreso', 'monto' => 10, 'concepto' => 'Sin cuenta'])
            ->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');

        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertEquals(0.0, (float) $this->sesion->fresh()->ingresos);
    }

    public function test_una_contrapartida_ajena_la_misma_caja_o_un_encabezado_da_422_y_no_deja_nada(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        Cuentas::sembrar($otra->id);
        $ajena = Cuentas::cuenta($otra->id, 'capital');
        $caja = Cuentas::cuenta($this->company->id, 'caja');
        $encabezado = \App\Models\Account::where('company_id', $this->company->id)->where('codigo', '1.1')->firstOrFail();

        foreach ([$ajena->id, $caja->id, $encabezado->id, 999999] as $cuentaId) {
            $this->postJson("/api/cash/{$this->sesion->id}/movements", [
                'tipo' => 'egreso', 'monto' => 10, 'concepto' => 'Mala cuenta', 'cuenta_contrapartida_id' => $cuentaId,
            ])->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
        }

        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertEquals(0.0, (float) $this->sesion->fresh()->egresos);
    }

    public function test_las_cuentas_de_cartera_no_se_mueven_con_un_comprobante_de_caja(): void
    {
        foreach (['cxc', 'cxp', 'cxc_relacionadas', 'cxp_relacionadas', 'anticipos_proveedores'] as $concepto) {
            $r = $this->movimiento('ingreso', 10, 'Cobro suelto', $concepto)->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
            $this->assertStringContainsString('Cartera', $r->json('errors.cuenta_contrapartida_id.0'));
        }

        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_periodo_cerrado_rechaza_el_movimiento_y_no_crea_nada(): void
    {
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => (int) now()->year, 'mes' => (int) now()->month,
            'estado' => 'cerrado', 'cerrado_at' => now()]);

        $r = $this->movimiento('ingreso', 50, 'En mes cerrado', 'capital')->assertStatus(422);

        $this->assertStringContainsString('está cerrado', json_encode($r->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertEquals(0.0, (float) $this->sesion->fresh()->ingresos);
    }

    public function test_una_caja_cerrada_no_recibe_movimientos(): void
    {
        $this->postJson("/api/cash/{$this->sesion->id}/close", ['saldo_final_contado' => 100])->assertOk();

        $this->movimiento('ingreso', 10, 'Tarde', 'capital')->assertStatus(422);

        $this->assertSame(0, CashMovement::count());
    }

    public function test_el_comprobante_es_un_pdf_con_el_monto_en_letras(): void
    {
        $this->movimiento('ingreso', 120.50, 'Aporte del socio', 'capital')->assertOk();
        $mov = CashMovement::firstOrFail();

        $r = $this->get("/api/cash-movements/{$mov->id}/comprobante?company_id={$this->company->id}")->assertOk();
        $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        // El HTML que se convierte en PDF lleva todo lo del comprobante
        $html = ComprobanteCaja::html($mov->fresh('cuenta'), 'Cajera Uno');
        foreach (['COMPROBANTE DE INGRESO DE CAJA', 'CI-000001', 'Aporte del socio', '3.1.01 · Capital', '$ 120.50',
            'CIENTO VEINTE CON 50/100 DÓLARES', 'Empresa Caja Test SA', '1791234567001', 'Elaborado por', 'Cajera Uno', 'Recibido por'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        // Y el PDF real lo dibuja (si pdftotext está instalado)
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $ruta = tempnam(sys_get_temp_dir(), 'cmp').'.pdf';
            file_put_contents($ruta, $r->getContent());
            try {
                $texto = (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
            } finally {
                @unlink($ruta);
            }
            $this->assertStringContainsString('CIENTO VEINTE CON 50/100 DÓLARES', $texto);
            $this->assertStringContainsString('CI-000001', $texto);
            $this->assertStringContainsString('Aporte del socio', $texto);
        }
    }

    public function test_el_comprobante_de_egreso_dice_egreso_y_el_de_otra_empresa_no_existe(): void
    {
        $this->movimiento('ingreso', 100, 'Fondo', 'capital')->assertOk();
        $this->movimiento('egreso', 33.33, 'Café', 'gastos_generales')->assertOk();
        $egreso = CashMovement::where('tipo', 'egreso')->firstOrFail();
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);

        $this->assertStringContainsString('COMPROBANTE DE EGRESO DE CAJA', ComprobanteCaja::html($egreso->fresh('cuenta')));
        $this->assertStringContainsString('TREINTA Y TRES CON 33/100 DÓLARES', ComprobanteCaja::html($egreso->fresh('cuenta')));
        $this->get("/api/cash-movements/{$egreso->id}/comprobante?company_id={$otra->id}")->assertNotFound();
        $this->get("/api/cash-movements/{$egreso->id}/comprobante")->assertStatus(422);
    }

    public function test_un_movimiento_anterior_sin_numero_tambien_se_imprime(): void
    {
        $viejo = $this->sesion->movements()->create(['tipo' => 'egreso', 'monto' => 5, 'concepto' => 'Anterior a T5.3']);

        $this->assertStringContainsString('Sin número', ComprobanteCaja::html($viejo));
        $r = $this->get("/api/cash-movements/{$viejo->id}/comprobante?company_id={$this->company->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertSame(0, JournalEntry::count(), 'Imprimir no asienta nada');
    }

    public function test_monto_en_letras(): void
    {
        $casos = [
            [120.50, 'CIENTO VEINTE CON 50/100 DÓLARES'],
            [0.99, 'CERO CON 99/100 DÓLARES'],
            [1, 'UNO CON 00/100 DÓLARES'],
            [100, 'CIEN CON 00/100 DÓLARES'],
            [101.01, 'CIENTO UNO CON 01/100 DÓLARES'],
            [21, 'VEINTIUNO CON 00/100 DÓLARES'],
            [1000, 'MIL CON 00/100 DÓLARES'],
            [31000, 'TREINTA Y UN MIL CON 00/100 DÓLARES'],
            [1500.75, 'MIL QUINIENTOS CON 75/100 DÓLARES'],
            [2000000, 'DOS MILLONES CON 00/100 DÓLARES'],
            [1234567.89, 'UN MILLÓN DOSCIENTOS TREINTA Y CUATRO MIL QUINIENTOS SESENTA Y SIETE CON 89/100 DÓLARES'],
            ['45.5', 'CUARENTA Y CINCO CON 50/100 DÓLARES'],
        ];
        foreach ($casos as [$monto, $letras]) {
            $this->assertSame($letras, MontoEnLetras::dolares($monto), "Monto $monto");
        }
    }

    // ------------------------------------------------------------ helpers

    private function abrirCaja(Company $empresa, float $inicial): CashSession
    {
        $this->postJson('/api/cash/open', ['company_id' => $empresa->id, 'saldo_inicial' => $inicial])->assertSuccessful();

        return CashSession::where('company_id', $empresa->id)->latest('id')->firstOrFail();
    }

    private function movimiento(string $tipo, float $monto, string $concepto, string $conceptoCuenta)
    {
        return $this->postJson("/api/cash/{$this->sesion->id}/movements", [
            'tipo' => $tipo, 'monto' => $monto, 'concepto' => $concepto,
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, $conceptoCuenta)->id,
        ]);
    }

    private function asientoDe(CashMovement $mov): JournalEntry
    {
        return JournalEntry::where('origen_type', $mov->getMorphClass())->where('origen_id', $mov->id)->firstOrFail();
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    private function assertLineas(JournalEntry $entry, array $esperado): void
    {
        $real = $entry->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->codigo, round((float) $l->debe, 2), round((float) $l->haber, 2)])->all();
        $ordenar = function (array $filas): array {
            $filas = array_map(fn ($f) => [(string) $f[0], round((float) $f[1], 2), round((float) $f[2], 2)], $filas);
            usort($filas, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            return $filas;
        };
        $this->assertEquals($ordenar($esperado), $ordenar($real), 'Líneas del asiento '.$entry->concepto);
    }
}
