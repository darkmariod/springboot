<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.5: cruce de saldos como forma de cobro y de pago.
 * Un cruce cancela una factura abierta de un contacto contra una compra abierta DEL MISMO contacto
 * (un contacto puede ser cliente y proveedor a la vez): los dos saldos bajan, el asiento es
 * Debe CxP / Haber CxC y no mueve caja ni bancos.
 */
class CruceSaldosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $ambos;   // cliente y proveedor a la vez
    private Contact $otro;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Cruce Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->ambos = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Cliente y Proveedor SA', 'es_cliente' => true, 'es_proveedor' => true,
        ]);
        $this->otro = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790099887001',
            'razon_social' => 'Otro Proveedor SA', 'es_cliente' => true, 'es_proveedor' => true,
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ cobro con cruce

    public function test_cobro_con_cruce_baja_los_dos_saldos_y_asienta_cxp_contra_cxc_sin_tocar_caja(): void
    {
        $factura = $this->facturar($this->ambos);                 // 115
        $compra = $this->comprar($this->ambos, 100);              // 115

        $respuesta = $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $compra->id]],
        ])->assertOk();

        $this->assertEquals(65.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(65.0, $respuesta->json('saldo'));

        $cruce = $this->asientoCruce();
        $this->assertLineas($cruce, [['2.1.01', 50, 0], ['1.1.03', 0, 50]]);
        $this->assertSame([], $this->lineasEnCajaOBancos($cruce), 'Un cruce no mueve caja ni bancos');

        // Rastro de pagos en las dos puntas
        $cobro = InvoicePayment::where('invoice_id', $factura->id)->firstOrFail();
        $this->assertSame('cruce_saldos', $cobro->forma_pago);
        $this->assertEquals(50.0, (float) $cobro->monto);
        $pago = PurchasePayment::where('purchase_id', $compra->id)->firstOrFail();
        $this->assertSame('cruce_saldos', $pago->forma_pago);
        $this->assertEquals(50.0, (float) $pago->monto);
        $this->assertSame(1, PaymentSplit::where('pagable_id', $factura->id)->where('pagable_type', $factura->getMorphClass())->where('tipo', 'cruce_saldos')->count());

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_cobro_con_varias_formas_incluye_el_cruce_y_cada_parte_tiene_su_asiento(): void
    {
        $factura = $this->facturar($this->ambos);                 // 115
        $compra = $this->comprar($this->ambos, 100);              // 115

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [
                ['tipo' => 'efectivo', 'valor' => 40],
                ['tipo' => 'cruce_saldos', 'valor' => 60, 'documento_cruce' => $compra->id],
            ],
        ])->assertOk();

        $this->assertEquals(15.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(55.0, (float) $compra->fresh()->saldo_pendiente);

        $cobro = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)
            ->where('concepto', 'like', 'Cobro factura%')->firstOrFail();
        $this->assertLineas($cobro, [['1.1.01', 40, 0], ['1.1.03', 0, 40]]);
        $this->assertLineas($this->asientoCruce(), [['2.1.01', 60, 0], ['1.1.03', 0, 60]]);

        $this->assertEqualsCanonicalizing(['efectivo', 'cruce_saldos'], InvoicePayment::where('invoice_id', $factura->id)->pluck('forma_pago')->all());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_cruce_total_deja_las_dos_en_cero(): void
    {
        $factura = $this->facturar($this->ambos);
        $compra = $this->comprar($this->ambos, 100);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 115, 'documento_cruce' => $compra->id]],
        ])->assertOk();

        $this->assertEquals(0.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $compra->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ pago con cruce

    public function test_pago_con_cruce_cancela_la_compra_contra_una_factura_del_mismo_contacto(): void
    {
        $compra = $this->comprar($this->ambos, 100);              // 115
        $factura = $this->facturar($this->ambos);                 // 115

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 30, 'documento_cruce' => $factura->id]],
        ])->assertOk();

        $this->assertEquals(85.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(85.0, (float) $factura->fresh()->saldo_pendiente);
        $cruce = $this->asientoCruce();
        $this->assertLineas($cruce, [['2.1.01', 30, 0], ['1.1.03', 0, 30]]);
        $this->assertSame([], $this->lineasEnCajaOBancos($cruce));
        $this->assertSame('cruce_saldos', PurchasePayment::where('purchase_id', $compra->id)->value('forma_pago'));
        $this->assertSame('cruce_saldos', InvoicePayment::where('invoice_id', $factura->id)->value('forma_pago'));
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_pago_mixto_transferencia_y_cruce(): void
    {
        $compra = $this->comprar($this->ambos, 100);
        $factura = $this->facturar($this->ambos);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [
                ['tipo' => 'transferencia', 'valor' => 15],
                ['tipo' => 'cruce_saldos', 'valor' => 100, 'documento_cruce' => $factura->id],
            ],
        ])->assertOk();

        $this->assertEquals(0.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(15.0, (float) $factura->fresh()->saldo_pendiente);
        $pago = JournalEntry::where('origen_type', $compra->getMorphClass())->where('origen_id', $compra->id)
            ->where('concepto', 'like', 'Pago compra%')->firstOrFail();
        $this->assertLineas($pago, [['2.1.01', 15, 0], ['1.1.02', 0, 15]]);
        $this->assertLineas($this->asientoCruce(), [['2.1.01', 100, 0], ['1.1.03', 0, 100]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ partes relacionadas

    public function test_el_cruce_usa_la_cuenta_de_cada_lado_segun_sea_parte_relacionada(): void
    {
        $this->ambos->update(['parte_relacionada' => true]);
        $factura = $this->facturar($this->ambos);
        $compra = $this->comprar($this->ambos, 100);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 70, 'documento_cruce' => $compra->id]],
        ])->assertOk();

        $this->assertLineas($this->asientoCruce(), [['2.1.11', 70, 0], ['1.1.09', 0, 70]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_cruce_entre_una_factura_relacionada_y_una_compra_que_no_lo_es(): void
    {
        $this->ambos->update(['parte_relacionada' => true]);
        $factura = $this->facturar($this->ambos);                 // nace en 1.1.09
        $this->ambos->update(['parte_relacionada' => false]);
        $compra = $this->comprar($this->ambos, 100);              // nace en 2.1.01

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 40, 'documento_cruce' => $factura->id]],
        ])->assertOk();

        $this->assertLineas($this->asientoCruce(), [['2.1.01', 40, 0], ['1.1.09', 0, 40]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ errores (422 en español, sin dejar nada)

    public function test_cruce_con_un_documento_de_otro_contacto_devuelve_422_y_no_cambia_nada(): void
    {
        $factura = $this->facturar($this->ambos);
        $ajena = $this->comprar($this->otro, 100);
        $asientos = JournalEntry::count();

        $r = $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $ajena->id]],
        ])->assertStatus(422);

        $this->assertStringContainsString('mismo cliente o proveedor', $r->json('errors.pagos.0'));
        $this->assertSinCambios($factura, $ajena, $asientos);

        $compra = $this->comprar($this->ambos, 100);
        $asientos = JournalEntry::count();
        $facturaAjena = $this->facturar($this->otro);
        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $facturaAjena->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');
        $this->assertSinCambios($compra, $facturaAjena, $asientos + 1); // +1 por la factura ajena que se emitió
    }

    public function test_cruce_sin_documento_contrario_devuelve_422(): void
    {
        $factura = $this->facturar($this->ambos);
        $this->comprar($this->ambos, 100);

        $r = $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50]],
        ])->assertStatus(422);
        $this->assertStringContainsString('documento', $r->json('errors.pagos.0'));
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame(0, InvoicePayment::count());

        // Documento que no existe
        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => 99999]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');
    }

    public function test_cruce_por_mas_que_el_saldo_del_documento_contrario_devuelve_422(): void
    {
        $factura = $this->facturar($this->ambos);                 // 115
        $compra = $this->comprar($this->ambos, 100);              // 115
        $compra->update(['saldo_pendiente' => 30]);               // ya se pagó casi todo
        $asientos = JournalEntry::count();

        $r = $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'efectivo', 'valor' => 20], ['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $compra->id]],
        ])->assertStatus(422);

        $this->assertStringContainsString('supera el saldo', $r->json('errors.pagos.0'));
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente, 'Todo el cobro se deshace, incluso la parte en efectivo');
        $this->assertEquals(30.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentSplit::count());
    }

    public function test_dos_cruces_contra_el_mismo_documento_no_pueden_pasar_de_su_saldo(): void
    {
        $factura = $this->facturar($this->ambos);                 // 115
        $compra = $this->comprar($this->ambos, 100);              // 115
        $compra->update(['saldo_pendiente' => 100]);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [
                ['tipo' => 'cruce_saldos', 'valor' => 60, 'documento_cruce' => $compra->id],
                ['tipo' => 'cruce_saldos', 'valor' => 55, 'documento_cruce' => $compra->id],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');

        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(100.0, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_cruce_por_mas_que_el_saldo_propio_devuelve_422(): void
    {
        $factura = $this->facturar($this->ambos);
        $compra = $this->comprar($this->ambos, 500);

        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 200, 'documento_cruce' => $compra->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(575.0, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_no_se_cruza_contra_una_factura_anulada_ni_de_otra_empresa(): void
    {
        $compra = $this->comprar($this->ambos, 100);
        $anulada = $this->facturar($this->ambos);
        $anulada->update(['estado' => 'anulado']);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 10, 'documento_cruce' => $anulada->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');

        $otraEmpresa = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $contactoAjeno = Contact::create(['company_id' => $otraEmpresa->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Mismo RUC, otra empresa']);
        $facturaAjena = Invoice::create(['company_id' => $otraEmpresa->id, 'contact_id' => $contactoAjeno->id, 'numero' => '001-001-000000500',
            'items' => [], 'total_sin_impuestos' => 50, 'total_impuesto' => 0, 'importe_total' => 50, 'forma_pago' => 'credito',
            'saldo_pendiente' => 50, 'estado' => 'emitida', 'fecha_emision' => now()]);

        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 10, 'documento_cruce' => $facturaAjena->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('pagos');
        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_el_formato_antiguo_con_cruce_ya_no_se_acepta_sin_documento(): void
    {
        $factura = $this->facturar($this->ambos);
        $compra = $this->comprar($this->ambos, 100);

        $this->postJson("/api/receivables/{$factura->id}/pay", ['monto' => 10, 'forma_pago' => 'cruce'])->assertStatus(422);
        $this->postJson("/api/payables/{$compra->id}/pay", ['monto' => 10, 'forma_pago' => 'cruce'])->assertStatus(422);
        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id, 'forma_pago' => 'cruce',
            'pagos' => [['purchase_id' => $compra->id, 'monto' => 10]],
        ])->assertStatus(422);

        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
    }

    // ------------------------------------------------------------ listado de documentos y catálogo

    public function test_lista_las_compras_abiertas_del_contacto_para_un_cobro_y_las_facturas_para_un_pago(): void
    {
        $factura = $this->facturar($this->ambos);
        $facturaOtro = $this->facturar($this->otro);
        $facturaAnulada = $this->facturar($this->ambos);
        $facturaAnulada->update(['estado' => 'anulado']);
        $facturaCobrada = $this->facturar($this->ambos);
        $facturaCobrada->update(['saldo_pendiente' => 0]);

        $compra = $this->comprar($this->ambos, 100);
        $compraOtro = $this->comprar($this->otro, 100);
        $compraPagada = $this->comprar($this->ambos, 50);
        $compraPagada->update(['saldo_pendiente' => 0]);

        $url = fn (string $lado, ?int $contacto = null) => '/api/cruce-saldos/documentos?company_id='.$this->company->id
            .'&contact_id='.($contacto ?? $this->ambos->id).'&lado='.$lado;

        $cobro = $this->getJson($url('cobro'))->assertOk();
        $this->assertSame([$compra->id], array_column($cobro->json(), 'id'));
        $this->assertSame(['id', 'numero', 'fecha', 'saldo'], array_keys($cobro->json('0')));
        $this->assertSame($compra->numero, $cobro->json('0.numero'));
        $this->assertSame($compra->fecha_emision->format('Y-m-d'), $cobro->json('0.fecha'));
        $this->assertEquals(115.0, $cobro->json('0.saldo'));

        $pago = $this->getJson($url('pago'))->assertOk();
        $this->assertSame([$factura->id], array_column($pago->json(), 'id'));
        $this->assertSame($factura->numero, $pago->json('0.numero'));
        $this->assertEquals(115.0, $pago->json('0.saldo'));

        $this->assertSame([$facturaOtro->id], array_column($this->getJson($url('pago', $this->otro->id))->json(), 'id'));
        $this->assertSame([$compraOtro->id], array_column($this->getJson($url('cobro', $this->otro->id))->json(), 'id'));

        $this->getJson($url('cualquiera'))->assertStatus(422)->assertJsonValidationErrors('lado');
    }

    public function test_las_carteras_traen_el_contacto_para_ofrecer_el_cruce(): void
    {
        $factura = $this->facturar($this->ambos);
        $compra = $this->comprar($this->ambos, 100);

        $cobrar = $this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk();
        $this->assertSame($this->ambos->id, $cobrar->json('cartera.0.contact_id'));
        $this->assertSame($factura->id, $cobrar->json('cartera.0.id'));
        $pagar = $this->getJson('/api/payables?company_id='.$this->company->id)->assertOk();
        $this->assertSame($this->ambos->id, $pagar->json('cartera.0.contact_id'));
        $this->assertSame($compra->id, $pagar->json('cartera.0.id'));
    }

    public function test_el_catalogo_marca_el_cruce_y_registrar_pagos_no_lo_acepta_como_cobro_normal(): void
    {
        $catalogo = collect($this->getJson('/api/catalogos/formas-pago')->assertOk()->json())->keyBy('value');
        $this->assertTrue($catalogo['cruce_saldos']['es_cruce']);
        $this->assertFalse($catalogo['efectivo']['es_cruce']);

        $factura = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $this->ambos->id, 'numero' => '001-001-000000777',
            'items' => [], 'total_sin_impuestos' => 10, 'total_impuesto' => 0, 'importe_total' => 10, 'forma_pago' => 'credito',
            'saldo_pendiente' => 10, 'estado' => 'emitida', 'fecha_emision' => now()]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\RegistrarPagos::class)->handle($factura, [['tipo' => 'cruce_saldos', 'valor' => 10]], '1.1.03', 'Cobro', 'cobro');
    }

    // ------------------------------------------------------------ helpers

    /** Factura a crédito de un servicio: $100 + IVA 15 = $115. */
    private function facturar(Contact $cliente): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Compra de un servicio: $base + IVA 15. */
    private function comprar(Contact $proveedor, float $base): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    private function asientoCruce(): JournalEntry
    {
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Cruce de saldos%')->get();
        $this->assertCount(1, $asientos, 'Se esperaba un solo asiento de cruce');

        return $asientos->first();
    }

    /** @return array<int,string> códigos de caja/bancos que aparecen en el asiento */
    private function lineasEnCajaOBancos(JournalEntry $entry): array
    {
        return $entry->lines()->with('account')->get()->pluck('account.codigo')->intersect(['1.1.01', '1.1.02'])->values()->all();
    }

    private function assertSinCambios(Model $a, Model $b, int $asientosEsperados): void
    {
        $this->assertSame($asientosEsperados, JournalEntry::count(), 'No debe quedar ningún asiento nuevo');
        $this->assertSame(0, PaymentSplit::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PurchasePayment::count());
        $this->assertEquals((float) $a->saldo_pendiente, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals((float) $b->saldo_pendiente, (float) $b->fresh()->saldo_pendiente);
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
