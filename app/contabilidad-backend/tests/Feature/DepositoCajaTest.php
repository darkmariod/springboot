<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\AccountingPeriod;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.3 / C2: depósito de caja a banco. Debe Bancos / Haber Caja con su movimiento de banco (papeleta, DP-000001) y el
 * tope "no depositar más de lo que hay en caja": el efectivo disponible es el saldo de la cuenta Caja del libro mayor.
 */
class DepositoCajaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Bank $banco;
    private CashSession $sesion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Deposito SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Caja']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/cash/open', ['company_id' => $this->company->id, 'saldo_inicial' => 0])->assertSuccessful();
        $this->sesion = CashSession::firstOrFail();
    }

    public function test_sin_efectivo_en_caja_no_hay_nada_que_depositar(): void
    {
        $this->assertEquals(0.0, $this->disponible());
        $this->depositar(0.01)->assertStatus(422)
            ->assertJsonValidationErrors('monto')
            ->assertJsonPath('errors.monto.0', 'No puedes depositar más de lo que hay en caja: disponible $0.00');
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_el_limite_es_exacto_igual_se_permite_un_centavo_mas_se_rechaza(): void
    {
        $this->ingresarEfectivo(250.00);
        $this->assertEquals(250.00, $this->disponible());

        $this->depositar(250.01)->assertStatus(422)
            ->assertJsonPath('errors.monto.0', 'No puedes depositar más de lo que hay en caja: disponible $250.00');
        $this->assertSame(0, BankMovement::count());
        $this->assertSame(1, JournalEntry::count(), 'Solo el asiento del ingreso de caja');

        $this->depositar(250.00)->assertCreated()
            ->assertJsonPath('numero', 'DP-000001')->assertJsonPath('disponible', 0);
        $this->assertEquals(0.0, $this->disponible());
        $this->depositar(0.01)->assertStatus(422);
    }

    public function test_el_deposito_asienta_debe_bancos_haber_caja_y_deja_su_movimiento_de_banco(): void
    {
        $this->ingresarEfectivo(500);

        $r = $this->depositar(300.75, 'PAP-8841', 'Cierre del lunes')->assertCreated();

        $mov = BankMovement::firstOrFail();
        $this->assertSame('DP-000001', $mov->numero);
        $this->assertSame('deposito', $mov->clase);
        $this->assertSame('credito', $mov->tipo);
        $this->assertEquals(300.75, (float) $mov->monto);
        $this->assertSame('PAP-8841', $mov->documento);
        $this->assertSame($this->banco->id, (int) $mov->bank_id);
        $this->assertFalse((bool) $mov->conciliado);
        $this->assertStringContainsString('Cierre del lunes', $mov->concepto);
        $this->assertSame((new CashRegister)->getMorphClass(), $mov->origen_type, 'El origen es la caja de donde sale el dinero');
        $this->assertSame(CashRegister::first()->id, (int) $mov->origen_id);

        $asiento = JournalEntry::where('origen_type', $mov->getMorphClass())->where('origen_id', $mov->id)->firstOrFail();
        $this->assertSame($r->json('asiento'), $asiento->numero);
        $this->assertSame(now()->toDateString(), $asiento->fecha->toDateString());
        $this->assertLineas($asiento, [['1.1.02', 300.75, 0], ['1.1.01', 0, 300.75]]);
        $this->assertEquals(199.25, $this->disponible());

        // El movimiento aparece en la conciliación del banco con su papeleta
        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$this->banco->id)->assertOk();
        $this->assertSame('PAP-8841', $lista->json('movimientos.0.documento'));
        $this->assertEquals(300.75, $lista->json('saldo_sistema'));
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_varios_depositos_se_numeran_y_suman_contra_el_disponible(): void
    {
        $this->ingresarEfectivo(100);

        $this->depositar(60, 'P-1')->assertCreated()->assertJsonPath('numero', 'DP-000001');
        $this->depositar(40, 'P-2')->assertCreated()->assertJsonPath('numero', 'DP-000002');
        $this->depositar(0.01, 'P-3')->assertStatus(422);

        $this->assertSame(['DP-000001', 'DP-000002'], BankMovement::orderBy('id')->pluck('numero')->all());
        $lista = $this->getJson('/api/deposits?company_id='.$this->company->id)->assertOk();
        $this->assertCount(2, $lista->json());
    }

    public function test_el_efectivo_de_una_venta_al_contado_tambien_se_puede_depositar(): void
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => Contact::firstOrFail()->id, 'forma_pago' => 'efectivo',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        $this->assertEquals(115.00, $this->disponible());
        $this->depositar(115.01)->assertStatus(422);
        $this->depositar(115.00)->assertCreated();
        $this->assertEquals(0.0, $this->disponible());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_el_deposito_queda_como_egreso_de_la_caja_abierta_sin_un_segundo_asiento(): void
    {
        $this->ingresarEfectivo(200);
        $asientos = JournalEntry::count();

        $this->depositar(80, 'P-9')->assertCreated();

        $sesion = $this->sesion->fresh();
        $this->assertEquals(80.0, (float) $sesion->egresos);
        $this->assertEquals(120.0, (float) $sesion->saldo_inicial + (float) $sesion->ingresos - (float) $sesion->egresos, 'El arqueo espera lo que queda');
        $salida = CashMovement::where('numero', 'DP-000001')->firstOrFail();
        $this->assertSame('egreso', $salida->tipo);
        $this->assertSame((new BankMovement)->getMorphClass(), $salida->origen_type);
        $this->assertSame(BankMovement::firstOrFail()->id, (int) $salida->origen_id);

        $this->assertSame($asientos + 1, JournalEntry::count(), 'Un solo asiento nuevo: el del depósito');
        $this->assertSame(0, JournalEntry::where('origen_type', $salida->getMorphClass())->where('origen_id', $salida->id)
            ->where('concepto', 'like', '%Egreso de caja%')->count());
    }

    public function test_un_deposito_con_fecha_pasada_solo_cuenta_el_efectivo_que_ya_habia(): void
    {
        $this->ingresarEfectivo(100);               // entra hoy

        $ayer = now()->subDay()->toDateString();
        $this->assertEquals(0.0, $this->disponible($ayer));
        $this->depositar(10, 'P-AYER', null, $ayer)->assertStatus(422)
            ->assertJsonPath('errors.monto.0', 'No puedes depositar más de lo que hay en caja: disponible $0.00');
        $this->depositar(10, 'P-HOY', null, now()->addDay()->toDateString())->assertCreated();
    }

    public function test_banco_de_otra_empresa_papeleta_vacia_o_monto_cero_dan_422_y_no_dejan_nada(): void
    {
        $this->ingresarEfectivo(100);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno']);
        $asientos = JournalEntry::count();

        $this->depositar(10, 'P-1', null, null, $ajeno->id)->assertStatus(422)->assertJsonValidationErrors('bank_id');
        $this->postJson('/api/deposits', ['company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'fecha' => now()->toDateString(), 'monto' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('documento');
        $this->depositar(0, 'P-1')->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertSame(0, BankMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
    }

    public function test_un_periodo_cerrado_rechaza_el_deposito_y_no_deja_nada(): void
    {
        $this->ingresarEfectivo(100);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => (int) now()->year, 'mes' => (int) now()->month,
            'estado' => 'cerrado', 'cerrado_at' => now()]);
        $movimientos = CashMovement::count();

        $this->depositar(50)->assertStatus(422);

        $this->assertSame(0, BankMovement::count());
        $this->assertSame($movimientos, CashMovement::count());
        $this->assertEquals(0.0, (float) $this->sesion->fresh()->egresos);
    }

    public function test_un_banco_con_su_propia_cuenta_contable_recibe_el_asiento_en_ella(): void
    {
        Account::create(['company_id' => $this->company->id, 'codigo' => '1.1.02.01', 'nombre' => 'Banco Test cta cte', 'tipo' => 'activo']);
        $this->banco->update(['cuenta_contable' => '1.1.02.01']);
        $this->ingresarEfectivo(100);

        $this->depositar(40)->assertCreated();

        $asiento = JournalEntry::where('concepto', 'like', 'Depósito a banco%')->firstOrFail();
        $this->assertLineas($asiento, [['1.1.02.01', 40, 0], ['1.1.01', 0, 40]]);
    }

    // ------------------------------------------------------------ helpers

    private function ingresarEfectivo(float $monto): void
    {
        $this->postJson("/api/cash/{$this->sesion->id}/movements", [
            'tipo' => 'ingreso', 'monto' => $monto, 'concepto' => 'Efectivo cobrado',
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, 'capital')->id,
        ])->assertOk();
    }

    private function disponible(?string $fecha = null): float
    {
        return (float) $this->getJson('/api/deposits/disponible?company_id='.$this->company->id.($fecha ? "&fecha=$fecha" : ''))->assertOk()->json('disponible');
    }

    private function depositar(float $monto, string $papeleta = 'PAP-1', ?string $observacion = null, ?string $fecha = null, ?int $bankId = null)
    {
        return $this->postJson('/api/deposits', [
            'company_id' => $this->company->id, 'bank_id' => $bankId ?? $this->banco->id, 'fecha' => $fecha ?? now()->toDateString(),
            'monto' => $monto, 'documento' => $papeleta, 'observacion' => $observacion,
        ]);
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
