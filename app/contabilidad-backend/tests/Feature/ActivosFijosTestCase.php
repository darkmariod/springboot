<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base de las pruebas de activos fijos (T7.2). El "hoy" queda fijo en el miércoles 2026-10-14: los meses que se pueden depreciar
 * (hasta el mes en curso, 2026-10) y las fechas de los asientos no dependen del día en que se corre la prueba.
 * Cada activo de prueba es una laptop de $1200 a 12 meses que empieza a depreciarse en enero de 2026, salvo que la prueba diga otra cosa.
 */
abstract class ActivosFijosTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Company $otra;
    protected Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Activos SA', 'dir_matriz' => 'Av. Activos 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->otra = Company::create([
            'ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        Cuentas::sembrar($this->otra->id);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor de Equipos', 'es_proveedor' => true,
        ]);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
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

    protected function cuentaId(string $concepto, ?Company $empresa = null): int
    {
        return Cuentas::cuenta(($empresa ?? $this->company)->id, $concepto)->id;
    }

    /** Datos de una laptop de $1200 a 12 meses; el alta se registra contra Capital. */
    protected function datosActivo(array $extra = []): array
    {
        return $extra + [
            'company_id' => $this->company->id, 'nombre' => 'Laptop Dell', 'grupo' => 'equipos_computacion',
            'fecha_adquisicion' => '2026-01-10', 'costo' => 1200, 'valor_residual' => 0, 'vida_util_meses' => 12,
            'fecha_inicio_depreciacion' => '2026-01-01', 'cuenta_contrapartida_id' => $this->cuentaId('capital'),
        ];
    }

    protected function crear(array $extra = []): TestResponse
    {
        return $this->postJson('/api/fixed-assets', $this->datosActivo($extra));
    }

    protected function activo(array $extra = []): FixedAsset
    {
        $r = $this->crear($extra);
        $r->assertCreated();

        return FixedAsset::findOrFail($r->json('id'));
    }

    /** POST /fixed-assets/depreciar con el cuerpo dado ({anio, mes} o {desde, hasta}). */
    protected function depreciar(array $datos, bool $simular = false): TestResponse
    {
        return $this->postJson('/api/fixed-assets/depreciar'.($simular ? '?simular=1' : ''), ['company_id' => $this->company->id] + $datos);
    }

    /** Un solo mes, "2026-03". */
    protected function mes(string $periodo, bool $simular = false): TestResponse
    {
        [$anio, $mes] = explode('-', $periodo);

        return $this->depreciar(['anio' => (int) $anio, 'mes' => (int) $mes], $simular);
    }

    protected function rango(string $desde, string $hasta, bool $simular = false): TestResponse
    {
        return $this->depreciar(['desde' => $desde, 'hasta' => $hasta], $simular);
    }

    protected function baja(FixedAsset $a, array $extra = []): TestResponse
    {
        return $this->postJson("/api/fixed-assets/{$a->id}/baja", $extra + ['company_id' => $this->company->id, 'fecha' => '2026-04-10', 'motivo' => 'Obsoleto']);
    }

    /** Saldo de la cuenta de un concepto: Debe − Haber por $signo (1 = naturaleza deudora, -1 = acreedora), sin borradores. */
    protected function saldo(string $concepto, int $signo = 1): float
    {
        $cuenta = Cuentas::cuenta($this->company->id, $concepto);
        $q = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $this->company->id)->where('journal_entries.estado', '!=', 'borrador')
            ->where('journal_entry_lines.account_id', $cuenta->id);

        return round($signo * ((float) $q->sum('journal_entry_lines.debe') - (float) (clone $q)->sum('journal_entry_lines.haber')), 2);
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

    /** Líneas [codigo, debe, haber] de un asiento, sin importar el orden, y que cuadre. */
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
