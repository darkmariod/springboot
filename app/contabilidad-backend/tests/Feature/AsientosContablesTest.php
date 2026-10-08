<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Purchase;
use App\Models\User;
use App\Services\RegistrarPagos;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 (cuadrar la contabilidad): cada prueba revisa las líneas reales del
 * asiento (cuenta, Debe y Haber), no solo el código HTTP.
 */
class AsientosContablesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001',
            'razon_social' => 'Empresa Contable Test SA',
            'dir_matriz' => 'Av. Test 123',
            'estab' => '001',
            'pto_emi' => '001',
            'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id,
            'tipo_identificacion' => '05',
            'identificacion' => '1700000001',
            'razon_social' => 'Cliente Test',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id,
            'tipo_identificacion' => '04',
            'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor Test',
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ---------------------------------------------------------------- T1.1

    public function test_pago_a_proveedor_asienta_debe_cxp_y_haber_caja_y_banco(): void
    {
        $compra = $this->compra(100);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [
                ['tipo' => 'efectivo', 'valor' => 60],
                ['tipo' => 'transferencia', 'valor' => 40],
            ],
        ])->assertOk();

        $this->assertAsiento($compra, [
            ['2.1.01', 100, 0],
            ['1.1.01', 0, 60],
            ['1.1.02', 0, 40],
        ]);
        $this->assertSame(0.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(2, PaymentSplit::where('pagable_id', $compra->id)->count());
    }

    public function test_cobro_de_factura_mantiene_debe_caja_y_haber_cxc(): void
    {
        $factura = $this->factura(100);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [
                ['tipo' => 'efectivo', 'valor' => 70],
                ['tipo' => 'transferencia', 'valor' => 30],
            ],
        ])->assertOk();

        $this->assertAsiento($factura, [
            ['1.1.01', 70, 0],
            ['1.1.02', 30, 0],
            ['1.1.03', 0, 100],
        ]);
        $this->assertSame(0.0, (float) $factura->fresh()->saldo_pendiente);
    }

    public function test_pago_a_proveedor_formato_antiguo_sigue_debe_cxp_haber_banco(): void
    {
        $compra = $this->compra(80);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'monto' => 80, 'forma_pago' => 'transferencia',
        ])->assertOk();

        $this->assertAsiento($compra, [
            ['2.1.01', 80, 0],
            ['1.1.02', 0, 80],
        ]);
    }

    public function test_pago_multiple_a_proveedores_debe_cxp_haber_caja(): void
    {
        $a = $this->compra(50, '001-001-000000101');
        $b = $this->compra(70, '001-001-000000102');

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'forma_pago' => 'efectivo',
            'pagos' => [
                ['purchase_id' => $a->id, 'monto' => 50],
                ['purchase_id' => $b->id, 'monto' => 70],
            ],
        ])->assertOk();

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertLineas($entry, [
            ['2.1.01', 120, 0],
            ['1.1.01', 0, 120],
        ]);
    }

    public function test_registrar_pagos_exige_un_sentido_valido(): void
    {
        $factura = $this->factura(10);

        $this->expectException(\InvalidArgumentException::class);
        app(RegistrarPagos::class)->handle(
            $factura, [['tipo' => 'efectivo', 'valor' => 10]], '1.1.03', 'Cobro', 'ni-cobro-ni-pago'
        );
    }

    // ---------------------------------------------------------------- T1.2

    public function test_nota_de_credito_acredita_cxc_solo_al_emitir_y_ya_baja_el_saldo_de_su_factura(): void
    {
        $factura = $this->factura(100);

        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id,
            'contact_id' => $this->cliente->id,
            'invoice_id' => $factura->id,
            'tipo' => 'interna',
            'motivo' => 'Descuento comercial',
            'importe_total' => 50,
        ])->assertCreated();
        $nota = CreditNote::firstOrFail();

        $this->assertAsiento($nota, [
            ['4.1.02', 50, 0],
            ['1.1.03', 0, 50],
        ]);
        // T3.2: la nota baja el saldo de su factura al emitirse; no queda saldo a favor que aplicar después
        $this->assertSame(50.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame(0.0, (float) $nota->fresh()->saldo_disponible);
        $asientosAntes = JournalEntry::where('company_id', $this->company->id)->count();

        // Aplicarla otra vez no restaría nada más: no queda saldo disponible
        $this->postJson("/api/credits/apply/{$factura->id}", [
            'tipo' => 'nota', 'id' => $nota->id, 'monto' => 50,
        ])->assertStatus(422);

        $this->assertSame($asientosAntes, JournalEntry::where('company_id', $this->company->id)->count());
        $this->assertSame(50.0, (float) $factura->fresh()->saldo_pendiente);

        // CxC se acreditó una sola vez por esta nota (50, no 100)
        $creditosCxc = JournalEntry::where('company_id', $this->company->id)->get()
            ->flatMap->lines->filter(fn ($l) => $l->account->codigo === '1.1.03')->sum('haber');
        $this->assertSame(50.0, (float) $creditosCxc);
    }

    public function test_anticipo_aplicado_a_factura_debe_anticipos_y_haber_cxc(): void
    {
        $factura = $this->factura(100);

        $this->postJson('/api/advances', [
            'company_id' => $this->company->id,
            'contact_id' => $this->cliente->id,
            'monto' => 80,
            'forma_pago' => 'efectivo',
        ])->assertCreated();
        $anticipo = Advance::firstOrFail();

        // Al recibirlo: Debe Caja / Haber Anticipos de clientes
        $this->assertAsiento($anticipo, [
            ['1.1.01', 80, 0],
            ['2.1.03', 0, 80],
        ]);

        $this->postJson("/api/credits/apply/{$factura->id}", [
            'tipo' => 'anticipo', 'id' => $anticipo->id, 'monto' => 50,
        ])->assertOk();

        // Al aplicarlo: Debe Anticipos de clientes / Haber CxC
        $this->assertAsiento($factura, [
            ['2.1.03', 50, 0],
            ['1.1.03', 0, 50],
        ]);
        $this->assertSame(30.0, (float) $anticipo->fresh()->saldo);
        $this->assertSame(50.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame('Anticipos de clientes', Account::where('company_id', $this->company->id)->where('codigo', '2.1.03')->value('nombre'));
    }

    // ---------------------------------------------------------------- T1.3

    public function test_plan_central_no_repite_codigos_ni_nombres_y_conserva_inventario_y_faltantes(): void
    {
        $cuentas = config('cuentas.cuentas');
        $this->assertNotEmpty($cuentas, 'Falta config/cuentas.php');

        $codigos = array_column($cuentas, 'codigo');
        $this->assertSame(array_values(array_unique($codigos)), $codigos, 'Hay códigos repetidos en config/cuentas.php');
        $nombres = array_column($cuentas, 'nombre');
        $this->assertSame(array_values(array_unique($nombres)), $nombres, 'Hay nombres repetidos en config/cuentas.php');

        // Los encabezados de grupo tampoco chocan con las cuentas
        $grupos = array_column(config('cuentas.grupos'), 0);
        $this->assertSame([], array_values(array_intersect($grupos, $codigos)));

        foreach ($cuentas as $concepto => $c) {
            $this->assertContains($c['tipo'], ['activo', 'pasivo', 'patrimonio', 'ingreso', 'gasto'], $concepto);
        }
        $this->assertSame('1.1.05', $cuentas['inventario']['codigo']);
        $this->assertSame('5.1.03', $cuentas['faltantes_sobrantes']['codigo']);
    }

    public function test_formas_de_pago_apuntan_a_cuentas_del_plan_central_y_tarjeta_no_usa_inventario(): void
    {
        $plan = config('cuentas.cuentas');
        $this->assertNotEmpty($plan);

        foreach (config('formas_pago') as $tipo => $forma) {
            if ($forma['cuenta'] === null) {
                continue; // "cuenta contable": la elige el usuario
            }
            $this->assertArrayHasKey($forma['cuenta'], $plan, "La forma de pago $tipo apunta a un concepto inexistente");
        }

        $codigoTarjeta = $plan[config('formas_pago.tarjeta_credito.cuenta')]['codigo'];
        $this->assertNotSame('1.1.05', $codigoTarjeta);
        $this->assertSame('1.1.08', $codigoTarjeta);
    }

    public function test_cobro_con_tarjeta_va_a_tarjetas_por_liquidar_y_no_a_inventario(): void
    {
        $factura = $this->factura(100);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [
                ['tipo' => 'efectivo', 'valor' => 40],
                ['tipo' => 'tarjeta_credito', 'valor' => 60],
            ],
        ])->assertOk();

        $this->assertAsiento($factura, [
            ['1.1.01', 40, 0],
            ['1.1.08', 60, 0],
            ['1.1.03', 0, 100],
        ]);
        $this->assertSame('Tarjetas por liquidar', Account::where('company_id', $this->company->id)->where('codigo', '1.1.08')->value('nombre'));
        $this->assertNull(Account::where('company_id', $this->company->id)->where('codigo', '1.1.05')->first());
    }

    public function test_el_plan_sembrado_trae_todas_las_cuentas_con_su_nombre_y_tipo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();

        $this->assertNotEmpty(config('cuentas.cuentas'));
        foreach (config('cuentas.cuentas') as $concepto => $c) {
            $cuenta = Account::where('company_id', $demo->id)->where('codigo', $c['codigo'])->first();
            $this->assertNotNull($cuenta, "El plan sembrado no trae $concepto ({$c['codigo']})");
            $this->assertSame($c['nombre'], $cuenta->nombre, $concepto);
            $this->assertSame($c['tipo'], $cuenta->tipo, $concepto);
        }
        $this->assertSame('Inventario', Account::where('company_id', $demo->id)->where('codigo', '1.1.05')->value('nombre'));
        $this->assertSame('Anticipos de clientes', Account::where('company_id', $demo->id)->where('codigo', '2.1.03')->value('nombre'));
    }

    public function test_anticipos_nomina_y_retenciones_ya_no_comparten_codigos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $demo->update(['plan' => 'completo']);
        $cliente = Contact::create(['company_id' => $demo->id, 'tipo_identificacion' => '05',
            'identificacion' => '1700000002', 'razon_social' => 'Cliente Demo']);
        $proveedor = Contact::create(['company_id' => $demo->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011224001', 'razon_social' => 'Proveedor Demo']);

        // 1) Anticipo de cliente
        $this->postJson('/api/advances', [
            'company_id' => $demo->id, 'contact_id' => $cliente->id, 'monto' => 100, 'forma_pago' => 'efectivo',
        ])->assertCreated();

        // 2) Rol de pagos cerrado (IESS y beneficios)
        $empleado = Employee::create(['company_id' => $demo->id, 'cedula' => '1700000003',
            'nombres' => 'Empleado Test', 'fecha_ingreso' => '2025-01-01', 'sueldo' => 1000]);
        $rol = Payroll::create(['company_id' => $demo->id, 'anio' => 2026, 'mes' => 9]);
        PayrollLine::create(['payroll_id' => $rol->id, 'employee_id' => $empleado->id,
            'sueldo' => 1000, 'aporte_personal' => 94.50, 'neto' => 905.50, 'aporte_patronal' => 121.50,
            'decimo_tercero' => 83.33, 'decimo_cuarto' => 39.17, 'vacaciones' => 41.67]);
        $this->postJson("/api/payrolls/{$rol->id}/close")->assertOk();

        // 3) Retenciones emitidas a un proveedor (IVA y renta)
        $compra = Purchase::create(['company_id' => $demo->id, 'contact_id' => $proveedor->id,
            'numero' => '001-001-000000500', 'fecha_emision' => now(), 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'saldo_pendiente' => 115]);
        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $demo->id, 'purchase_id' => $compra->id,
            'tipo' => 'iva', 'porcentaje' => 30, 'base_imponible' => 100,
        ])->assertCreated();
        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $demo->id, 'purchase_id' => $compra->id,
            'tipo' => 'renta', 'porcentaje' => 2, 'base_imponible' => 100,
        ])->assertCreated();

        $entradas = JournalEntry::where('company_id', $demo->id)->orderBy('id')->get();
        $this->assertCount(4, $entradas);
        [$anticipo, $nomina, $retIva, $retRenta] = $entradas->all();

        $this->assertLineas($anticipo, [['1.1.01', 100, 0], ['2.1.03', 0, 100]]);
        $this->assertLineas($retIva, [['2.1.01', 30, 0], ['2.1.09', 0, 30]]);
        $this->assertLineas($retRenta, [['2.1.01', 2, 0], ['2.1.10', 0, 2]]);
        $this->assertLineas($nomina, [
            ['5.2.01', 1000, 0], ['5.2.02', 121.50, 0], ['5.2.03', 164.17, 0],
            ['2.1.04', 0, 216.00], ['2.1.05', 0, 164.17], ['2.1.06', 0, 0], ['2.1.07', 0, 905.50],
        ]);

        // El nombre de cada cuenta corresponde al concepto que se asentó ahí
        $nombre = fn (string $codigo) => Account::where('company_id', $demo->id)->where('codigo', $codigo)->value('nombre');
        $this->assertSame('Anticipos de clientes', $nombre('2.1.03'));
        $this->assertSame('IESS por pagar', $nombre('2.1.04'));
        $this->assertSame('Beneficios sociales por pagar', $nombre('2.1.05'));
        $this->assertSame('Retención IVA por pagar', $nombre('2.1.09'));
        $this->assertSame('Retención renta por pagar', $nombre('2.1.10'));
    }

    public function test_los_controladores_no_escriben_codigos_de_cuenta_a_mano(): void
    {
        $archivos = array_merge(
            glob(app_path('Http/Controllers/*.php')),
            [app_path('Services/RegistrarPagos.php')],
        );
        $this->assertNotEmpty($archivos);

        $encontrados = [];
        foreach ($archivos as $archivo) {
            if (preg_match_all('/[\'"][1-5]\.\d{1,2}\.\d{2}[\'"]/', file_get_contents($archivo), $m)) {
                $encontrados[basename($archivo)] = array_unique($m[0]);
            }
        }
        $this->assertSame([], $encontrados, 'Usa config/cuentas.php (App\\Support\\Cuentas) en lugar de códigos escritos a mano.');
    }

    // ------------------------------------------------------------- helpers

    private function factura(float $total, string $numero = '001-001-000000010'): Invoice
    {
        return Invoice::create([
            'company_id' => $this->company->id,
            'contact_id' => $this->cliente->id,
            'numero' => $numero,
            'items' => [],
            'total_sin_impuestos' => $total,
            'total_impuesto' => 0,
            'importe_total' => $total,
            'forma_pago' => 'credito',
            'saldo_pendiente' => $total,
            'estado' => 'emitida',
            'fecha_emision' => now(),
        ]);
    }

    private function compra(float $total, string $numero = '001-001-000000099'): Purchase
    {
        return Purchase::create([
            'company_id' => $this->company->id,
            'contact_id' => $this->proveedor->id,
            'numero' => $numero,
            'fecha_emision' => now(),
            'items' => [],
            'total_sin_impuestos' => $total,
            'total_impuesto' => 0,
            'importe_total' => $total,
            'saldo_pendiente' => $total,
        ]);
    }

    /** Asiento generado por el documento de origen: líneas [codigo, debe, haber] y totales cuadrados. */
    private function assertAsiento(Model $origen, array $esperado): void
    {
        $asientos = JournalEntry::where('origen_type', $origen->getMorphClass())
            ->where('origen_id', $origen->getKey())->get();
        $this->assertCount(1, $asientos, 'Se esperaba exactamente un asiento para '.class_basename($origen).' #'.$origen->getKey());
        $this->assertLineas($asientos->first(), $esperado);
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

        $debe = round(array_sum(array_column($real, 1)), 2);
        $haber = round(array_sum(array_column($real, 2)), 2);
        $this->assertSame($debe, $haber, 'El asiento no cuadra');
        $this->assertEquals($debe, $entry->total_debe);
        $this->assertEquals($haber, $entry->total_haber);
    }
}
