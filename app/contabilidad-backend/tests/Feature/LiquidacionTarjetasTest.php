<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\AccountingPeriod;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\CardSettlement;
use App\Models\CardTransaction;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.3 / C5: liquidación de tarjetas. Las ventas con tarjeta debitan 1.1.08 "Tarjetas por liquidar"; al registrar lo que
 * depositó el procesador se acredita esa cuenta por el bruto contra Bancos (neto), Comisiones bancarias y Retenciones
 * anticipadas, y el depósito queda en el banco para conciliarlo. Bruto = neto + comisión + retenciones al centavo.
 */
class LiquidacionTarjetasTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Bank $banco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Tarjetas SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        $this->cliente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Tarjeta']);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_la_liquidacion_asienta_neto_comision_y_retenciones_contra_tarjetas_por_liquidar(): void
    {
        $this->ventaConTarjeta(100);                       // 115.00
        $this->ventaConTarjeta(100);                       // 115.00
        $this->assertEquals(230.0, $this->saldo('1.1.08'));

        $r = $this->liquidar([
            'monto_bruto' => 230, 'comision' => 4.60, 'retencion_iva' => 0.55, 'retencion_renta' => 0.05, 'monto_neto' => 224.80, 'lote' => 'L-778',
        ])->assertCreated();

        $s = CardSettlement::firstOrFail();
        $this->assertSame('LQ-000001', $s->numero);
        $this->assertSame($r->json('id'), $s->id);
        $this->assertSame($this->banco->id, (int) $s->bank_id);
        $this->assertEquals([230.0, 4.6, 0.55, 0.05, 224.8], [(float) $s->monto_bruto, (float) $s->comision, (float) $s->retencion_iva, (float) $s->retencion_renta, (float) $s->monto_neto]);

        $asiento = JournalEntry::where('origen_type', $s->getMorphClass())->where('origen_id', $s->id)->firstOrFail();
        $this->assertSame('2026-10-12', $asiento->fecha->toDateString());
        $this->assertEquals(230.0, (float) $asiento->total_debe);
        $this->assertEquals(230.0, (float) $asiento->total_haber);
        $this->assertLineas($asiento, [
            ['1.1.02', 224.80, 0],      // neto al banco
            ['5.3.01', 4.60, 0],        // comisión
            ['1.1.06', 0.55, 0],        // retención de IVA
            ['1.1.06', 0.05, 0],        // retención de renta
            ['1.1.08', 0, 230],         // el bruto sale de tarjetas por liquidar
        ]);

        // El depósito queda en el banco, con el neto, para conciliarlo
        $mov = BankMovement::firstOrFail();
        $this->assertSame('credito', $mov->tipo);
        $this->assertEquals(224.80, (float) $mov->monto);
        $this->assertSame('LQ-000001', $mov->numero);
        $this->assertSame('liquidacion_tarjeta', $mov->clase);
        $this->assertSame('L-778', $mov->documento);
        $this->assertSame($s->getMorphClass(), $mov->origen_type);
        $this->assertSame($s->id, (int) $mov->origen_id);
        $this->assertFalse((bool) $mov->conciliado);
    }

    public function test_al_liquidar_todas_las_ventas_con_tarjeta_el_saldo_vuelve_a_cero_y_el_chequeo_estricto_pasa(): void
    {
        $this->ventaConTarjeta(100);
        $this->ventaConTarjeta(200);
        $this->ventaConTarjeta(50);                        // 115 + 230 + 57.50 = 402.50
        $this->assertEquals(402.50, $this->saldo('1.1.08'));
        $this->assertEquals(402.50, round((float) CardTransaction::sum('monto'), 2));

        $r = $this->liquidar(['monto_bruto' => 402.50, 'comision' => 8.05, 'retencion_iva' => 0.97, 'retencion_renta' => 0.08])->assertCreated();   // neto calculado: 393.40
        $this->assertEquals(393.40, (float) $r->json('monto_neto'));
        $this->postJson('/api/card/settlements/'.$r->json('id').'/asignar', ['transaction_ids' => CardTransaction::pluck('id')->all()])->assertOk();

        $this->assertEquals(0.0, $this->saldo('1.1.08'), 'Tarjetas por liquidar vuelve a cero');
        $this->assertSame('conciliada', CardSettlement::firstOrFail()->estado);
        $this->assertSame(0, CardTransaction::pendientes()->count());
        $this->chequeoEstricto()->assertExitCode(0);
        // La línea informativa compara los vouchers pendientes con el mayor
        $this->chequeoEstricto()->expectsOutputToContain('Tarjetas por liquidar (1.1.08): ventas pendientes $0.00 · mayor $0.00 · diferencia $0.00')->assertExitCode(0);
    }

    public function test_un_centavo_de_diferencia_entre_bruto_y_neto_mas_descuentos_da_422_y_no_crea_nada(): void
    {
        $this->ventaConTarjeta(100);
        $asientos = JournalEntry::count();

        foreach ([224.81, 224.79, 300.00] as $neto) {
            $this->liquidar(['monto_bruto' => 230, 'comision' => 4.60, 'retencion_iva' => 0.55, 'retencion_renta' => 0.05, 'monto_neto' => $neto])
                ->assertStatus(422)->assertJsonValidationErrors('monto_neto');
        }
        $r = $this->liquidar(['monto_bruto' => 100, 'comision' => 60, 'retencion_iva' => 50]);        // los descuentos superan al bruto
        $r->assertStatus(422)->assertJsonValidationErrors('monto_neto');
        $this->assertStringContainsString('bruto', $r->json('errors.monto_neto.0'));

        $this->assertSame(0, CardSettlement::count());
        $this->assertSame(0, BankMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(115.0, $this->saldo('1.1.08'));
    }

    public function test_sin_comision_ni_retenciones_el_neto_es_el_bruto(): void
    {
        $this->ventaConTarjeta(100);

        $this->liquidar(['monto_bruto' => 115])->assertCreated()->assertJsonPath('monto_neto', '115.00');

        $asiento = JournalEntry::where('concepto', 'like', 'Liquidación de tarjetas%')->firstOrFail();
        $this->assertLineas($asiento, [['1.1.02', 115, 0], ['1.1.08', 0, 115]]);
        $this->assertEquals(0.0, $this->saldo('1.1.08'));
    }

    public function test_el_banco_es_obligatorio_y_debe_ser_de_la_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno']);

        $this->postJson('/api/card/settlements', ['company_id' => $this->company->id, 'fecha' => '2026-10-12', 'procesador' => 'Datafast', 'monto_bruto' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('bank_id');
        $this->liquidar(['monto_bruto' => 10], $ajeno->id)->assertStatus(422)->assertJsonValidationErrors('bank_id');
        $this->assertSame(0, CardSettlement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_un_periodo_cerrado_rechaza_la_liquidacion_y_no_deja_nada(): void
    {
        $this->ventaConTarjeta(100);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 11, 'estado' => 'cerrado', 'cerrado_at' => now()]);

        $this->liquidar(['monto_bruto' => 115, 'fecha' => '2026-11-03'])->assertStatus(422);

        $this->assertSame(0, CardSettlement::count());
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(1, JournalEntry::count(), 'Solo el asiento de la venta');
    }

    public function test_eliminar_la_liquidacion_reversa_el_asiento_y_quita_el_deposito_del_banco(): void
    {
        $this->ventaConTarjeta(100);
        $id = $this->liquidar(['monto_bruto' => 115, 'comision' => 2.30])->assertCreated()->json('id');
        $this->assertEquals(0.0, $this->saldo('1.1.08'));

        $this->deleteJson("/api/card/settlements/$id")->assertNoContent();

        $this->assertSame(0, CardSettlement::count());
        $this->assertSame(0, BankMovement::count());
        $this->assertEquals(115.0, $this->saldo('1.1.08'), 'Las ventas vuelven a estar por liquidar');
        $this->assertEquals(0.0, $this->saldo('1.1.02'));
        $this->assertSame(1, JournalEntry::where('concepto', 'like', 'Reversión liquidación%')->count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_no_se_elimina_una_liquidacion_cuyo_deposito_ya_esta_conciliado(): void
    {
        $this->ventaConTarjeta(100);
        $id = $this->liquidar(['monto_bruto' => 115])->assertCreated()->json('id');
        $mov = BankMovement::firstOrFail();
        $this->postJson("/api/bank-movements/{$mov->id}/toggle")->assertOk();

        $this->deleteJson("/api/card/settlements/$id")->assertStatus(422)->assertJsonValidationErrors('liquidacion');

        $this->assertSame(1, CardSettlement::count());
        $this->assertSame(1, BankMovement::count());
        $this->assertEquals(0.0, $this->saldo('1.1.08'));
    }

    public function test_los_numeros_de_liquidacion_son_por_empresa_y_seguidos(): void
    {
        $this->ventaConTarjeta(100);
        $this->ventaConTarjeta(100);
        $this->liquidar(['monto_bruto' => 115])->assertCreated()->assertJsonPath('numero', 'LQ-000001');
        $this->liquidar(['monto_bruto' => 115])->assertCreated()->assertJsonPath('numero', 'LQ-000002');
    }

    // ------------------------------------------------------------ helpers

    private function ventaConTarjeta(float $base): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'tarjeta',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function liquidar(array $datos, ?int $bankId = null)
    {
        return $this->postJson('/api/card/settlements', $datos + [
            'company_id' => $this->company->id, 'bank_id' => $bankId ?? $this->banco->id, 'fecha' => '2026-10-12', 'procesador' => 'Datafast',
        ]);
    }

    /** Saldo Debe − Haber de una cuenta en el mayor. */
    private function saldo(string $codigo): float
    {
        $lineas = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.company_id', $this->company->id)->where('accounts.codigo', $codigo);

        return round((float) $lineas->sum('journal_entry_lines.debe') - (float) (clone $lineas)->sum('journal_entry_lines.haber'), 2);
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
