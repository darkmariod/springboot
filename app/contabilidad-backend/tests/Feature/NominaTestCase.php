<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\SimpleEntry;
use App\Support\Cuentas;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base de las pruebas de nómina (T7.1). El "hoy" queda fijo en el miércoles 2026-10-14 para que los meses, los días hábiles y la
 * fecha de los asientos no dependan del día en que se corre la prueba. El rol de prueba es el de octubre de 2026 (mes 10).
 */
abstract class NominaTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected int $anio = 2026;
    protected int $mes = 10;
    private int $cedulas = 0;
    private int $facturas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Nómina Test SA', 'dir_matriz' => 'Av. Nómina 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        $this->como('contador');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function como(string $rol): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => $rol, 'activo' => true]));
    }

    protected function empleado(float $sueldo, array $extra = [], ?Company $empresa = null): Employee
    {
        $empresa ??= $this->company;

        return Employee::create($extra + [
            'company_id' => $empresa->id, 'cedula' => '17'.str_pad((string) ++$this->cedulas, 8, '0', STR_PAD_LEFT),
            'nombres' => 'Empleado '.$this->cedulas, 'cargo' => 'Técnico', 'fecha_ingreso' => '2025-01-01', 'sueldo' => $sueldo,
        ]);
    }

    protected function cliente(array $extra = [], ?Company $empresa = null): Contact
    {
        $empresa ??= $this->company;

        return Contact::create($extra + [
            'company_id' => $empresa->id, 'tipo_identificacion' => '05', 'es_cliente' => true,
            'identificacion' => '18'.str_pad((string) (++$this->cedulas), 8, '0', STR_PAD_LEFT), 'razon_social' => 'Cliente Empleado '.$this->cedulas,
        ]);
    }

    /** Factura a crédito con su asiento de venta (Debe CxC / Haber Ventas), para que la CxC del mayor cuadre con el saldo de la factura. */
    protected function factura(Contact $cliente, float $total, ?string $fecha = null, ?Company $empresa = null): Invoice
    {
        $empresa ??= $this->company;
        $factura = Invoice::create([
            'company_id' => $empresa->id, 'contact_id' => $cliente->id,
            'numero' => '001-001-'.str_pad((string) ++$this->facturas, 9, '0', STR_PAD_LEFT),
            'items' => [], 'total_sin_impuestos' => $total, 'total_impuesto' => 0, 'importe_total' => $total,
            'forma_pago' => 'credito', 'saldo_pendiente' => $total, 'estado' => 'emitida', 'fecha_emision' => $fecha ?? now(),
        ]);
        SimpleEntry::make($empresa->id, 'Venta '.$factura->numero, [
            Cuentas::linea(Cuentas::cxcPara($cliente), $total, 0, $factura->numero),
            Cuentas::linea('ventas', 0, $total, $factura->numero),
        ], $factura);

        return $factura;
    }

    protected function movimiento(Employee $e, array $datos): TestResponse
    {
        return $this->postJson('/api/employee-movements', $datos + [
            'company_id' => $e->company_id, 'employee_id' => $e->id, 'fecha' => '2026-10-14',
        ]);
    }

    protected function generar(?int $anio = null, ?int $mes = null, array $extras = []): TestResponse
    {
        return $this->postJson('/api/payrolls/generate', ['company_id' => $this->company->id, 'anio' => $anio ?? $this->anio, 'mes' => $mes ?? $this->mes]
            + ($extras ? ['extras' => $extras] : []));
    }

    protected function cerrar(int $payrollId): TestResponse
    {
        return $this->postJson("/api/payrolls/{$payrollId}/close");
    }

    /** La línea (JSON) de un empleado dentro de la respuesta de generar. */
    protected function linea(TestResponse $r, Employee $e): array
    {
        foreach ($r->json('lines') as $l) {
            if ((int) $l['employee_id'] === $e->id) {
                return $l;
            }
        }
        $this->fail("El rol no trae a {$e->nombres}");
    }

    protected function asientoDe(Model $origen): JournalEntry
    {
        return JournalEntry::where('origen_type', $origen->getMorphClass())->where('origen_id', $origen->getKey())
            ->where('concepto', 'not like', 'Reversión%')->orderBy('id')->firstOrFail();
    }

    protected function chequeoEstricto(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    protected function assertLineas(JournalEntry $entry, array $esperado): void
    {
        $real = $entry->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->codigo, round((float) $l->debe, 2), round((float) $l->haber, 2)])->all();
        $ordenar = function (array $filas): array {
            $filas = array_map(fn ($f) => [(string) $f[0], round((float) $f[1], 2), round((float) $f[2], 2)], $filas);
            usort($filas, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            return $filas;
        };
        $this->assertEquals($ordenar($esperado), $ordenar($real), 'Líneas del asiento '.$entry->concepto);
        $this->assertEquals(round((float) $entry->total_debe, 2), round((float) $entry->total_haber, 2), 'El asiento cuadra');
    }
}
