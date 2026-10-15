<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\AccountingPeriod;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.3 / C3: el movimiento de banco escrito a mano lleva contrapartida, número MB- y asiento (con el número de cheque o
 * documento), salvo que se marque "ya contabilizado". Los que nacen de un pago o cobro no se asientan de nuevo.
 * El cheque posfechado se asienta el día de su emisión, pero no cuenta en el saldo ni se concilia hasta su fecha de cobro.
 */
class MovimientoBancoManualTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Bank $banco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Banco Manual SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_un_credito_manual_asienta_debe_bancos_haber_la_contrapartida_con_su_numero(): void
    {
        $r = $this->mover(['tipo' => 'credito', 'monto' => 500, 'concepto' => 'Aporte de socio', 'numero_documento' => 'TRF-5521'], 'capital')->assertCreated();

        $mov = BankMovement::firstOrFail();
        $this->assertSame('MB-000001', $mov->numero);
        $this->assertSame('MB-000001', $r->json('numero'));
        $this->assertSame('manual', $mov->clase);
        $this->assertSame('TRF-5521', $mov->documento, 'numero_documento es otro nombre de documento');
        $this->assertNull($mov->origen_type);
        $this->assertSame(Cuentas::cuenta($this->company->id, 'capital')->id, (int) $mov->cuenta_contrapartida_id);

        $asiento = $this->asientoDe($mov);
        $this->assertSame('2026-10-12', $asiento->fecha->toDateString());
        $this->assertLineas($asiento, [['1.1.02', 500, 0], ['3.1.01', 0, 500]]);
        $this->assertStringContainsString('MB-000001', $asiento->concepto);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_debito_manual_asienta_debe_la_contrapartida_haber_bancos(): void
    {
        $this->mover(['tipo' => 'credito', 'monto' => 900, 'concepto' => 'Fondeo'], 'capital')->assertCreated();
        $this->mover(['tipo' => 'debito', 'monto' => 120.40, 'concepto' => 'Comisión mensual', 'documento' => 'ND-77'], 'comisiones_bancarias')->assertCreated();

        $mov = BankMovement::where('numero', 'MB-000002')->firstOrFail();
        $this->assertSame('debito', $mov->tipo);
        $this->assertLineas($this->asientoDe($mov), [['5.3.01', 120.40, 0], ['1.1.02', 0, 120.40]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_sin_contrapartida_no_se_crea_nada_salvo_que_este_marcado_como_ya_contabilizado(): void
    {
        $this->postJson('/api/bank-movements', $this->base(['tipo' => 'credito', 'monto' => 10, 'concepto' => 'Sin cuenta']))
            ->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());

        // Ya contabilizado: solo se concilia, no lleva número ni asiento
        $this->postJson('/api/bank-movements', $this->base(['tipo' => 'credito', 'monto' => 10, 'concepto' => 'Ya asentado', 'ya_contabilizado' => true]))
            ->assertCreated()->assertJsonPath('clase', 'sin_asiento')->assertJsonPath('numero', null);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(1, BankMovement::count());
    }

    public function test_contrapartida_ajena_encabezado_o_la_misma_cuenta_del_banco_dan_422(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        Cuentas::sembrar($otra->id);
        $ids = [
            Cuentas::cuenta($otra->id, 'capital')->id,
            Account::where('company_id', $this->company->id)->where('codigo', '1.1')->value('id'),
            Cuentas::cuenta($this->company->id, 'bancos')->id,
        ];
        foreach ($ids as $id) {
            $this->postJson('/api/bank-movements', $this->base(['tipo' => 'credito', 'monto' => 10, 'concepto' => 'Mala', 'cuenta_contrapartida_id' => $id]))
                ->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
        }
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_las_cuentas_de_cartera_no_se_mueven_con_un_movimiento_de_banco_a_mano(): void
    {
        foreach (['cxc', 'cxp'] as $concepto) {
            $this->mover(['tipo' => 'credito', 'monto' => 150, 'concepto' => 'Cobro suelto'], $concepto)
                ->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
        }
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_el_banco_debe_ser_de_la_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno']);

        $this->postJson('/api/bank-movements', $this->base(['tipo' => 'credito', 'monto' => 10, 'concepto' => 'X', 'bank_id' => $ajeno->id,
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, 'capital')->id]))->assertStatus(422)->assertJsonValidationErrors('bank_id');
        $this->assertSame(0, BankMovement::count());
    }

    public function test_cheque_posfechado_se_asienta_hoy_pero_no_cuenta_en_el_saldo_ni_se_concilia(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->mover(['tipo' => 'credito', 'monto' => 1000, 'concepto' => 'Fondeo', 'fecha' => '2026-10-12'], 'capital')->assertCreated();
        $this->mover(['tipo' => 'debito', 'monto' => 300, 'concepto' => 'Cheque a proveedor', 'fecha' => '2026-10-12',
            'forma' => 'cheque', 'documento' => '000451', 'fecha_cobro' => '2026-10-22'], 'compras')->assertCreated();

        $cheque = BankMovement::where('documento', '000451')->firstOrFail();
        $this->assertTrue($cheque->esPosfechado());
        // El asiento queda en la fecha de emisión: el gasto y la salida de Bancos ya están en los libros
        $this->assertSame('2026-10-12', $this->asientoDe($cheque)->fecha->toDateString());
        $this->assertLineas($this->asientoDe($cheque), [['5.1.01', 300, 0], ['1.1.02', 0, 300]]);

        // Hoy: el cheque se ve, pero no entra en el saldo de conciliación
        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$this->banco->id)->assertOk();
        $this->assertEquals(1000.0, $lista->json('saldo_sistema'));
        $this->assertEquals(-300.0, $lista->json('saldo_posfechado'));
        $this->assertSame(1, $lista->json('posfechados'));
        $fila = collect($lista->json('movimientos'))->firstWhere('documento', '000451');
        $this->assertTrue($fila['posfechado']);
        $this->assertSame('2026-10-22', $fila['fecha_cobro']);
        $this->assertSame('cheque', $fila['forma']);

        // No se concilia antes de su fecha de cobro
        $this->postJson("/api/bank-movements/{$cheque->id}/toggle")->assertStatus(422);
        $this->assertFalse((bool) $cheque->fresh()->conciliado);

        // Llega la fecha de cobro: ya cuenta y se puede conciliar
        Carbon::setTestNow('2026-10-22 08:00:00');
        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$this->banco->id)->assertOk();
        $this->assertEquals(700.0, $lista->json('saldo_sistema'));
        $this->assertEquals(0.0, $lista->json('saldo_posfechado'));
        $this->assertSame(0, $lista->json('posfechados'));
        $this->postJson("/api/bank-movements/{$cheque->id}/toggle")->assertOk();
        $this->assertTrue((bool) $cheque->fresh()->conciliado);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_la_fecha_de_cobro_solo_aplica_a_cheques_y_no_puede_ser_anterior(): void
    {
        $this->mover(['tipo' => 'debito', 'monto' => 10, 'concepto' => 'Transferencia', 'forma' => 'transferencia', 'fecha_cobro' => '2026-10-30'], 'compras')
            ->assertStatus(422)->assertJsonValidationErrors('fecha_cobro');
        $this->mover(['tipo' => 'debito', 'monto' => 10, 'concepto' => 'Cheque', 'forma' => 'cheque', 'fecha' => '2026-10-12', 'fecha_cobro' => '2026-10-01'], 'compras')
            ->assertStatus(422)->assertJsonValidationErrors('fecha_cobro');
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_un_movimiento_de_un_pago_trae_su_origen_y_no_se_asienta_de_nuevo(): void
    {
        $proveedor = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor Banco SA', 'es_cliente' => false, 'es_proveedor' => true]);
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id, 'numero' => '001-001-000000001', 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();
        $compra = Purchase::firstOrFail();

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cheque_banco', 'valor' => 115, 'bank_id' => $this->banco->id, 'documento' => '004512']],
        ])->assertOk();

        $mov = BankMovement::firstOrFail();
        $this->assertSame($compra->getMorphClass(), $mov->origen_type);
        $this->assertNull($mov->numero);
        $this->assertNull($mov->clase);
        // Un solo asiento por el pago (Debe CxP / Haber Bancos), y ninguno "Movimiento de banco"
        $this->assertSame(1, JournalEntry::where('concepto', 'like', 'Pago compra%')->count());
        $this->assertSame(0, JournalEntry::where('concepto', 'like', 'Movimiento de banco%')->count());
        $this->assertSame(0, JournalEntry::where('origen_type', $mov->getMorphClass())->count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_periodo_cerrado_rechaza_el_movimiento_y_no_deja_nada(): void
    {
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 10, 'estado' => 'cerrado', 'cerrado_at' => now()]);

        $this->mover(['tipo' => 'credito', 'monto' => 10, 'concepto' => 'En mes cerrado', 'fecha' => '2026-10-12'], 'capital')->assertStatus(422);

        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    // ------------------------------------------------------------ helpers

    private function base(array $extra = []): array
    {
        return array_merge(['company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'fecha' => '2026-10-12'], $extra);
    }

    private function mover(array $datos, string $conceptoContra)
    {
        return $this->postJson('/api/bank-movements', $this->base($datos + [
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, $conceptoContra)->id,
        ]));
    }

    private function asientoDe(BankMovement $mov): JournalEntry
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
