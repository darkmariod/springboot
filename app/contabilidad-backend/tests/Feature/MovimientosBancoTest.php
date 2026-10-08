<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.6c: un pago o cobro con forma de pago por banco (cheque, transferencia) deja su movimiento en
 * la conciliación bancaria (egreso = 'debito' para pagos, ingreso = 'credito' para cobros), con el número
 * de cheque o documento. No genera otro asiento: el del pago ya pasa por la cuenta Bancos.
 */
class MovimientosBancoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;
    private Contact $cliente;
    private Bank $banco;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Banco Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor Banco SA', 'es_cliente' => false, 'es_proveedor' => true,
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001',
            'razon_social' => 'Cliente Banco',
        ]);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_pago_a_proveedor_con_cheque_de_banco_crea_un_egreso_con_el_numero_de_cheque(): void
    {
        $compra = $this->comprar(100);                                    // 115

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cheque_banco', 'valor' => 115, 'bank_id' => $this->banco->id, 'documento' => '004512']],
        ])->assertOk();

        $mov = BankMovement::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($this->banco->id, $mov->bank_id);
        $this->assertSame('debito', $mov->tipo);                          // egreso
        $this->assertEquals(115.0, (float) $mov->monto);
        $this->assertSame('004512', $mov->documento);
        $this->assertSame('Pago compra '.$compra->numero, $mov->concepto);
        $this->assertFalse((bool) $mov->conciliado);
        $this->assertSame($compra->getMorphClass(), $mov->origen_type);
        $this->assertSame($compra->id, (int) $mov->origen_id);

        // El asiento sigue siendo uno solo (Debe CxP / Haber Bancos): el movimiento no lo duplica
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Pago compra%')->get();
        $this->assertCount(1, $asientos);
        $this->assertLineasAsiento($asientos->first(), [['2.1.01', 115, 0], ['1.1.02', 0, 115]]);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_pago_con_efectivo_y_transferencia_solo_crea_movimiento_para_la_parte_bancaria(): void
    {
        $compra = $this->comprar(100);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [
                ['tipo' => 'efectivo', 'valor' => 15],
                ['tipo' => 'transferencia', 'valor' => 100, 'bank_id' => $this->banco->id, 'documento' => 'TRF-889'],
            ],
        ])->assertOk();

        $movs = BankMovement::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $movs);
        $this->assertSame('debito', $movs->first()->tipo);
        $this->assertEquals(100.0, (float) $movs->first()->monto);
        $this->assertSame('TRF-889', $movs->first()->documento);
    }

    public function test_pago_en_efectivo_o_sin_banco_no_crea_movimiento(): void
    {
        $a = $this->comprar(100);
        $b = $this->comprar(100);

        $this->postJson("/api/payables/{$a->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 115, 'bank_id' => $this->banco->id]]])->assertOk();
        $this->postJson("/api/payables/{$b->id}/pay", ['pagos' => [['tipo' => 'transferencia', 'valor' => 115]]])->assertOk();

        $this->assertSame(0, BankMovement::count());
    }

    public function test_formato_antiguo_de_pago_con_banco_tambien_crea_el_movimiento(): void
    {
        $compra = $this->comprar(100);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'monto' => 115, 'forma_pago' => 'cheque', 'bank_id' => $this->banco->id, 'cheque_numero' => '778899',
        ])->assertOk();

        $mov = BankMovement::firstOrFail();
        $this->assertSame('debito', $mov->tipo);
        $this->assertSame('778899', $mov->documento);
        $this->assertEquals(115.0, (float) $mov->monto);
        $this->assertSame($compra->id, (int) $mov->origen_id);
    }

    public function test_cobro_de_cliente_por_banco_crea_un_ingreso(): void
    {
        $factura = $this->facturar();                                     // 115

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cheque_banco', 'valor' => 100, 'bank_id' => $this->banco->id, 'documento' => 'CH-3321']],
        ])->assertOk();
        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'monto' => 15, 'forma_pago' => 'transferencia', 'bank_id' => $this->banco->id,
        ])->assertOk();

        $movs = BankMovement::where('company_id', $this->company->id)->orderBy('id')->get();
        $this->assertCount(2, $movs);
        $this->assertSame(['credito', 'credito'], $movs->pluck('tipo')->all());
        $this->assertEquals([100.0, 15.0], $movs->map(fn ($m) => (float) $m->monto)->all());
        $this->assertSame('CH-3321', $movs[0]->documento);
        $this->assertSame('Cobro factura '.$factura->numero, $movs[0]->concepto);
        $this->assertSame($factura->getMorphClass(), $movs[0]->origen_type);
        $this->assertSame($factura->id, (int) $movs[0]->origen_id);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_banco_de_otra_empresa_da_422_y_no_deja_pago_ni_movimiento(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $bancoAjeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno']);
        $compra = $this->comprar(100);
        $asientos = JournalEntry::count();

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'transferencia', 'valor' => 115, 'bank_id' => $bancoAjeno->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('bank_id');

        $this->assertSame(0, BankMovement::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_la_conciliacion_lista_el_documento_y_el_movimiento_manual_sigue_igual(): void
    {
        $compra = $this->comprar(100);
        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cheque_banco', 'valor' => 115, 'bank_id' => $this->banco->id, 'documento' => '004512']],
        ])->assertOk();

        // Manual, como siempre: sin documento ni origen
        $this->postJson('/api/bank-movements', [
            'company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'fecha' => '2026-10-01',
            'tipo' => 'credito', 'monto' => 500, 'concepto' => 'Aporte inicial',
        ])->assertCreated();

        // y uno manual con número de documento (opcional)
        $this->postJson('/api/bank-movements', [
            'company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'fecha' => '2026-10-02',
            'tipo' => 'credito', 'monto' => 0.01, 'concepto' => 'Depósito', 'documento' => 'DEP-77',
        ])->assertCreated()->assertJsonPath('documento', 'DEP-77');

        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$this->banco->id)->assertOk();
        $this->assertCount(3, $lista->json('movimientos'));
        $porConcepto = collect($lista->json('movimientos'))->keyBy('concepto');
        $this->assertSame('004512', $porConcepto['Pago compra '.$compra->numero]['documento']);
        $this->assertNull($porConcepto['Aporte inicial']['documento']);
        $this->assertNull($porConcepto['Aporte inicial']['origen_type']);
        $this->assertEquals(round(500 - 115 + 0.01, 2), $lista->json('saldo_sistema'));

        // El movimiento manual se concilia igual que siempre
        $manual = BankMovement::where('concepto', 'Aporte inicial')->firstOrFail();
        $this->postJson("/api/bank-movements/{$manual->id}/toggle")->assertOk();
        $this->assertTrue((bool) $manual->fresh()->conciliado);
    }

    // ------------------------------------------------------------ helpers

    private function comprar(float $base): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function facturar(): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    private function assertLineasAsiento(JournalEntry $entry, array $esperado): void
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
