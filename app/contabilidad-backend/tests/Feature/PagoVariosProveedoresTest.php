<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.6a: pago a varios proveedores en un solo comprobante.
 * Cada compra recibe sus PurchasePayment; hay un solo asiento con una línea Debe de CxP por cuenta
 * (normal o de partes relacionadas) y un Haber por cada forma de pago. La suma de las formas debe
 * ser igual al total; el cruce de saldos no se admite aquí.
 */
class PagoVariosProveedoresTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedorA;
    private Contact $proveedorB;
    private Bank $banco;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Pago Multiple SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedorA = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor A SA', 'es_cliente' => false, 'es_proveedor' => true,
        ]);
        $this->proveedorB = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011224001',
            'razon_social' => 'Proveedor B Relacionado SA', 'es_cliente' => false, 'es_proveedor' => true, 'parte_relacionada' => true,
        ]);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_paga_dos_compras_de_dos_proveedores_con_efectivo_y_cheque_de_banco(): void
    {
        $a = $this->comprar($this->proveedorA, 100);              // 115, CxP 2.1.01
        $b = $this->comprar($this->proveedorB, 200);              // 230, CxP relacionadas 2.1.11

        $resp = $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115], ['purchase_id' => $b->id, 'monto' => 230]],
            'formas' => [
                ['tipo' => 'efectivo', 'valor' => 200],
                ['tipo' => 'cheque_banco', 'valor' => 145, 'bank_id' => $this->banco->id, 'documento' => '009001'],
            ],
        ])->assertOk();

        $this->assertTrue($resp->json('ok'));
        $this->assertEquals(345.0, $resp->json('pagado'));
        $this->assertSame(2, $resp->json('facturas'));
        $this->assertEquals(0.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $b->fresh()->saldo_pendiente);

        // Un solo asiento: Debe cada CxP por su cuenta / Haber cada forma
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Pago múltiple%')->get();
        $this->assertCount(1, $asientos);
        $this->assertLineas($asientos->first(), [['2.1.01', 115, 0], ['2.1.11', 230, 0], ['1.1.01', 0, 200], ['1.1.02', 0, 145]]);

        // Cada compra con sus propios pagos: A se cubre con efectivo; B con el resto del efectivo y el cheque
        $this->assertEquals([['efectivo', 115.0, null, null]], $this->pagosDe($a));
        $this->assertEquals([['efectivo', 85.0, null, null], ['cheque_banco', 145.0, $this->banco->id, '009001']], $this->pagosDe($b));
        $this->assertSame(3, PaymentSplit::where('company_id', $this->company->id)->count());

        // Un solo movimiento de banco por el valor completo del cheque, con su número
        $mov = BankMovement::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $mov);
        $this->assertSame('debito', $mov->first()->tipo);
        $this->assertEquals(145.0, (float) $mov->first()->monto);
        $this->assertSame('009001', $mov->first()->documento);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_pago_parcial_baja_el_saldo_de_cada_compra_solo_en_lo_pagado(): void
    {
        $a = $this->comprar($this->proveedorA, 100);              // 115
        $b = $this->comprar($this->proveedorA, 100);              // 115

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 100], ['purchase_id' => $b->id, 'monto' => 15.50]],
            'formas' => [['tipo' => 'transferencia', 'valor' => 115.50, 'bank_id' => $this->banco->id]],
        ])->assertOk();

        $this->assertEquals(15.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals(99.5, (float) $b->fresh()->saldo_pendiente);
        $asiento = JournalEntry::where('concepto', 'like', 'Pago múltiple%')->firstOrFail();
        $this->assertLineas($asiento, [['2.1.01', 115.5, 0], ['1.1.02', 0, 115.5]]);
        $this->assertEquals([['transferencia', 100.0, $this->banco->id, null]], $this->pagosDe($a));
        $this->assertEquals([['transferencia', 15.5, $this->banco->id, null]], $this->pagosDe($b));
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_una_compra_repetida_en_la_lista_se_suma_y_se_valida_contra_su_saldo(): void
    {
        $a = $this->comprar($this->proveedorA, 100);              // 115

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 70], ['purchase_id' => $a->id, 'monto' => 70]],
            'formas' => [['tipo' => 'efectivo', 'valor' => 140]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');

        $this->assertEquals(115.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertSame(0, PurchasePayment::count());
    }

    public function test_la_suma_de_las_formas_debe_ser_igual_al_total_y_no_se_registra_nada_si_no(): void
    {
        $a = $this->comprar($this->proveedorA, 100);
        $b = $this->comprar($this->proveedorB, 100);
        $asientos = JournalEntry::count();

        $resp = $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115], ['purchase_id' => $b->id, 'monto' => 115]],
            'formas' => [['tipo' => 'efectivo', 'valor' => 200]],
        ])->assertStatus(422)->assertJsonValidationErrors('formas');
        $this->assertStringContainsString('debe ser igual al total a pagar', $resp->json('errors.formas.0'));

        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(0, PurchasePayment::count());
        $this->assertSame(0, PaymentSplit::count());
        $this->assertSame(0, BankMovement::count());
        $this->assertEquals(115.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals(115.0, (float) $b->fresh()->saldo_pendiente);
    }

    public function test_no_admite_cruce_de_saldos_en_el_pago_multiple(): void
    {
        $a = $this->comprar($this->proveedorA, 100);
        $asientos = JournalEntry::count();

        $resp = $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115]],
            'formas' => [['tipo' => 'cruce_saldos', 'valor' => 115, 'documento_cruce' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('formas');
        $this->assertStringContainsString('Cruce de saldos', $resp->json('errors.formas.0'));

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id, 'forma_pago' => 'cruce',
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115]],
        ])->assertStatus(422)->assertJsonValidationErrors('forma_pago');

        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(115.0, (float) $a->fresh()->saldo_pendiente);
    }

    public function test_un_pago_mayor_al_saldo_o_de_otra_empresa_da_422(): void
    {
        $a = $this->comprar($this->proveedorA, 100);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $contactoAjeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor ajeno', 'es_cliente' => false, 'es_proveedor' => true]);
        $ajena = Purchase::create(['company_id' => $otra->id, 'contact_id' => $contactoAjeno->id, 'numero' => '001-001-000000500',
            'fecha_emision' => now(), 'items' => [], 'total_sin_impuestos' => 50, 'total_impuesto' => 0, 'importe_total' => 50, 'saldo_pendiente' => 50]);

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 200]],
            'formas' => [['tipo' => 'efectivo', 'valor' => 200]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 10], ['purchase_id' => $ajena->id, 'monto' => 50]],
            'formas' => [['tipo' => 'efectivo', 'valor' => 60]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');

        $this->assertEquals(115.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals(50.0, (float) $ajena->fresh()->saldo_pendiente);
        $this->assertSame(0, PurchasePayment::count());
    }

    public function test_el_formato_antiguo_con_forma_pago_sigue_funcionando_y_deja_pagos_por_compra(): void
    {
        $a = $this->comprar($this->proveedorA, 100);
        $b = $this->comprar($this->proveedorB, 100);

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id, 'forma_pago' => 'transferencia', 'bank_id' => $this->banco->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115], ['purchase_id' => $b->id, 'monto' => 115]],
        ])->assertOk();

        $asiento = JournalEntry::where('concepto', 'like', 'Pago múltiple%')->firstOrFail();
        $this->assertLineas($asiento, [['2.1.01', 115, 0], ['2.1.11', 115, 0], ['1.1.02', 0, 230]]);
        $this->assertEquals([['transferencia', 115.0, $this->banco->id, null]], $this->pagosDe($a));
        $this->assertEquals([['transferencia', 115.0, $this->banco->id, null]], $this->pagosDe($b));
        $this->assertEquals(230.0, (float) BankMovement::firstOrFail()->monto);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ helpers

    private function comprar(Contact $proveedor, float $base): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** @return array<int,array{0:string,1:float,2:?int,3:?string}> forma, monto, banco y cheque de cada pago de la compra */
    private function pagosDe(Purchase $compra): array
    {
        return PurchasePayment::where('purchase_id', $compra->id)->orderBy('id')->get()
            ->map(fn ($p) => [$p->forma_pago, (float) $p->monto, $p->bank_id, $p->cheque_numero])->all();
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
        $this->assertEquals(array_sum(array_column($real, 1)), array_sum(array_column($real, 2)), 'El asiento no cuadra');
        $this->assertEquals(round(array_sum(array_column($real, 1)), 2), (float) $entry->total_debe);
    }
}
