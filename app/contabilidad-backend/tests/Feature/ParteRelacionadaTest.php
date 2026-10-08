<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.4: datos tributarios y contables del contacto.
 *   - Parte relacionada: las facturas a un cliente relacionado debitan 1.1.09 (en vez de 1.1.03) y las
 *     compras a un proveedor relacionado acreditan 2.1.11 (en vez de 2.1.01). Cobros, pagos, notas de crédito,
 *     anticipos y retenciones siguen la cuenta con que nació el documento.
 *   - Cuenta contable por defecto del proveedor: los servicios y gastos de sus compras van a esa cuenta (no a 5.1.01).
 *   - Clase de contribuyente (RISE, emprendedor, negocio popular, otros), con sugerencia desde la consulta al SRI.
 */
class ParteRelacionadaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $clienteRel;
    private Contact $proveedor;
    private Contact $proveedorRel;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Relacionadas Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = $this->contacto('1700000001', 'Cliente Normal');
        $this->clienteRel = $this->contacto('1700000002', 'Cliente Relacionado', ['parte_relacionada' => true]);
        $this->proveedor = $this->contacto('1790011223001', 'Proveedor Normal', ['tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true]);
        $this->proveedorRel = $this->contacto('1790011224001', 'Proveedor Relacionado', ['tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true, 'parte_relacionada' => true]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ catálogo de cuentas

    public function test_el_plan_central_trae_las_cuentas_de_relacionadas_con_codigos_libres(): void
    {
        $plan = config('cuentas.cuentas');
        $this->assertSame(['codigo' => '1.1.09', 'nombre' => 'Cuentas por cobrar relacionadas', 'tipo' => 'activo'], $plan['cxc_relacionadas']);
        $this->assertSame(['codigo' => '2.1.11', 'nombre' => 'Cuentas por pagar relacionadas', 'tipo' => 'pasivo'], $plan['cxp_relacionadas']);

        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $this->assertSame('Cuentas por cobrar relacionadas', Account::where('company_id', $demo->id)->where('codigo', '1.1.09')->value('nombre'));
        $this->assertSame('Cuentas por pagar relacionadas', Account::where('company_id', $demo->id)->where('codigo', '2.1.11')->value('nombre'));
    }

    // ------------------------------------------------------------ ficha del contacto

    public function test_el_contacto_guarda_clase_de_contribuyente_cuenta_y_parte_relacionada(): void
    {
        $cuenta = $this->cuentaGasto('5.1.20', 'Servicios básicos');

        $resp = $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790099999001',
            'razon_social' => 'Proveedor Nuevo', 'es_proveedor' => true, 'parte_relacionada' => true,
            'clase_contribuyente' => 'negocio_popular', 'cuenta_contable_id' => $cuenta->id,
        ])->assertCreated();

        $this->assertSame('negocio_popular', $resp->json('clase_contribuyente'));
        $this->assertSame($cuenta->id, $resp->json('cuenta_contable_id'));
        $this->assertTrue($resp->json('parte_relacionada'));

        $contacto = Contact::findOrFail($resp->json('id'));
        $this->putJson("/api/contacts/{$contacto->id}", ['clase_contribuyente' => 'rise', 'cuenta_contable_id' => null])->assertOk();
        $this->assertSame('rise', $contacto->fresh()->clase_contribuyente);
        $this->assertNull($contacto->fresh()->cuenta_contable_id);
    }

    public function test_clase_de_contribuyente_invalida_devuelve_422_en_espanol(): void
    {
        $r = $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000077',
            'razon_social' => 'Cliente X', 'clase_contribuyente' => 'gigante',
        ])->assertStatus(422);
        $this->assertStringContainsString('clase de contribuyente', $r->json('errors.clase_contribuyente.0'));

        $this->putJson("/api/contacts/{$this->cliente->id}", ['clase_contribuyente' => 'gigante'])->assertStatus(422)
            ->assertJsonValidationErrors('clase_contribuyente');
        $this->assertNull($this->cliente->fresh()->clase_contribuyente);
    }

    public function test_la_cuenta_contable_debe_ser_de_la_misma_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajena = Account::create(['company_id' => $otra->id, 'codigo' => '5.1.20', 'nombre' => 'Ajena', 'tipo' => 'gasto']);

        $r = $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790088888001',
            'razon_social' => 'Proveedor Y', 'cuenta_contable_id' => $ajena->id,
        ])->assertStatus(422);
        $this->assertStringContainsString('no pertenece a esta empresa', $r->json('errors.cuenta_contable_id.0'));

        $this->putJson("/api/contacts/{$this->proveedor->id}", ['cuenta_contable_id' => $ajena->id])->assertStatus(422)
            ->assertJsonValidationErrors('cuenta_contable_id');
        $this->assertNull($this->proveedor->fresh()->cuenta_contable_id);
    }

    public function test_el_catalogo_expone_las_clases_de_contribuyente(): void
    {
        $r = $this->getJson('/api/catalogos/clases-contribuyente')->assertOk();
        $this->assertSame(['rise', 'emprendedor', 'negocio_popular', 'otros'], array_column($r->json(), 'value'));
        $this->assertSame('RISE', $r->json('0.label'));
    }

    // ------------------------------------------------------------ ventas: cliente relacionado

    public function test_factura_a_cliente_relacionado_debita_cxc_relacionadas_y_a_otro_cliente_cxc(): void
    {
        $normal = $this->facturar($this->cliente);
        $relacionada = $this->facturar($this->clienteRel);

        $this->assertAsiento($normal, [['1.1.03', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);
        $this->assertAsiento($relacionada, [['1.1.09', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);
        $this->assertSame('Cuentas por cobrar relacionadas',
            Account::where('company_id', $this->company->id)->where('codigo', '1.1.09')->value('nombre'));
    }

    public function test_cobro_de_factura_relacionada_acredita_cxc_relacionadas_en_ambos_formatos(): void
    {
        $a = $this->facturar($this->clienteRel);
        $this->postJson("/api/receivables/{$a->id}/pay", [
            'pagos' => [['tipo' => 'efectivo', 'valor' => 65], ['tipo' => 'transferencia', 'valor' => 50]],
        ])->assertOk();
        $this->assertAsientoPago($a, 'Cobro factura', [['1.1.01', 65, 0], ['1.1.02', 50, 0], ['1.1.09', 0, 115]]);

        $b = $this->facturar($this->clienteRel);
        $this->postJson("/api/receivables/{$b->id}/pay", ['monto' => 115, 'forma_pago' => 'transferencia'])->assertOk();
        $this->assertAsientoPago($b, 'Cobro factura', [['1.1.02', 115, 0], ['1.1.09', 0, 115]]);

        $c = $this->facturar($this->cliente);
        $this->postJson("/api/receivables/{$c->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 115]]])->assertOk();
        $this->assertAsientoPago($c, 'Cobro factura', [['1.1.01', 115, 0], ['1.1.03', 0, 115]]);
    }

    public function test_el_documento_conserva_su_cuenta_aunque_el_contacto_cambie_despues(): void
    {
        $emitidaNormal = $this->facturar($this->cliente);
        $emitidaRel = $this->facturar($this->clienteRel);
        // Hacia adelante: lo ya emitido no se reprocesa, así que su cobro debe cerrar la cuenta donde nació
        $this->cliente->update(['parte_relacionada' => true]);
        $this->clienteRel->update(['parte_relacionada' => false]);

        $this->postJson("/api/receivables/{$emitidaNormal->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 115]]])->assertOk();
        $this->postJson("/api/receivables/{$emitidaRel->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 115]]])->assertOk();

        $this->assertAsientoPago($emitidaNormal, 'Cobro factura', [['1.1.01', 115, 0], ['1.1.03', 0, 115]]);
        $this->assertAsientoPago($emitidaRel, 'Cobro factura', [['1.1.01', 115, 0], ['1.1.09', 0, 115]]);

        // Y una factura nueva ya usa la cuenta que corresponde hoy
        $nueva = $this->facturar($this->cliente);
        $this->assertAsiento($nueva, [['1.1.09', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_documento_sin_asiento_toma_la_cuenta_segun_la_condicion_actual_del_contacto(): void
    {
        // Facturas cargadas sin asiento de venta (p. ej. importadas): no hay cuenta de origen que seguir
        $factura = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $this->clienteRel->id, 'numero' => '001-001-000000900',
            'items' => [], 'total_sin_impuestos' => 40, 'total_impuesto' => 0, 'importe_total' => 40, 'forma_pago' => 'credito',
            'saldo_pendiente' => 40, 'estado' => 'emitida', 'fecha_emision' => now()]);
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 40]]])->assertOk();

        $this->assertAsientoPago($factura, 'Cobro factura', [['1.1.01', 40, 0], ['1.1.09', 0, 40]]);
    }

    public function test_nota_de_credito_sigue_la_cuenta_de_la_factura_y_ya_baja_su_saldo(): void
    {
        $factura = $this->facturar($this->clienteRel);

        $nc = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->clienteRel->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Descuento', 'importe_total' => 23,
        ])->assertCreated();
        $notaId = $nc->json('id');
        $asientoNc = JournalEntry::where('origen_type', (new \App\Models\CreditNote)->getMorphClass())->where('origen_id', $notaId)->firstOrFail();
        $this->assertLineas($asientoNc, [['4.1.02', 23, 0], ['1.1.09', 0, 23]]);

        // T3.2: la nota baja el saldo de su factura al emitirse (la CxC ya se acreditó, en la misma cuenta de la factura);
        // ya no queda saldo a favor que aplicar y aplicarla de nuevo no genera asiento ni resta otra vez
        $antes = JournalEntry::count();
        $this->assertEquals(92.0, (float) $factura->fresh()->saldo_pendiente);
        $this->postJson("/api/credits/apply/{$factura->id}", ['tipo' => 'nota', 'id' => $notaId, 'monto' => 23])->assertStatus(422);
        $this->assertSame($antes, JournalEntry::count());
        $this->assertEquals(92.0, (float) $factura->fresh()->saldo_pendiente);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_nota_de_credito_de_otra_cuenta_se_reclasifica_al_aplicarla_a_la_factura(): void
    {
        $normal = $this->facturar($this->cliente);
        // El cliente ya pagó casi todo: la factura (115) solo debe 10
        $this->postJson("/api/receivables/{$normal->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 105]]])->assertOk();
        // Una nota de 20 sobre esa factura acredita 1.1.03: baja la deuda a 0 y los 10 que sobran quedan a favor del cliente
        $nc = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $normal->id,
            'tipo' => 'interna', 'motivo' => 'Saldo a favor', 'importe_total' => 20,
        ])->assertCreated();
        $this->assertEquals(0.0, (float) $normal->fresh()->saldo_pendiente);
        $this->assertEquals(10.0, (float) \App\Models\CreditNote::findOrFail($nc->json('id'))->saldo_disponible);
        // El cliente pasa a ser relacionado y se le factura de nuevo: esa factura vive en 1.1.09
        $this->cliente->update(['parte_relacionada' => true]);
        $relacionada = $this->facturar($this->cliente);
        $this->assertAsiento($relacionada, [['1.1.09', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);

        $this->postJson("/api/credits/apply/{$relacionada->id}", ['tipo' => 'nota', 'id' => $nc->json('id'), 'monto' => 10])->assertOk();

        // Para que cada cuenta quede con su saldo real: Debe 1.1.03 (deshace el crédito de la nota) / Haber 1.1.09
        $reclasificacion = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Aplicación de nota de crédito%')->firstOrFail();
        $this->assertLineas($reclasificacion, [['1.1.03', 10, 0], ['1.1.09', 0, 10]]);
        $this->assertEquals(105.0, (float) $relacionada->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $normal->fresh()->saldo_pendiente);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_anticipo_aplicado_a_factura_relacionada_acredita_cxc_relacionadas(): void
    {
        $factura = $this->facturar($this->clienteRel);
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->clienteRel->id, 'monto' => 50, 'forma_pago' => 'efectivo',
        ])->assertCreated();
        $anticipo = \App\Models\Advance::firstOrFail();

        $this->postJson("/api/credits/apply/{$factura->id}", ['tipo' => 'anticipo', 'id' => $anticipo->id, 'monto' => 50])->assertOk();

        $uso = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)
            ->where('concepto', 'like', 'Uso de anticipo%')->firstOrFail();
        $this->assertLineas($uso, [['2.1.03', 50, 0], ['1.1.09', 0, 50]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ compras: proveedor relacionado

    public function test_compra_a_proveedor_relacionado_acredita_cxp_relacionadas_y_a_otro_proveedor_cxp(): void
    {
        $normal = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $relacionada = $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);

        $this->assertAsiento($normal, [['5.1.01', 100, 0], ['1.1.04', 15, 0], ['2.1.01', 0, 115]]);
        $this->assertAsiento($relacionada, [['5.1.01', 100, 0], ['1.1.04', 15, 0], ['2.1.11', 0, 115]]);
    }

    public function test_pago_a_proveedor_relacionado_debita_cxp_relacionadas_en_ambos_formatos(): void
    {
        $a = $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);
        $this->postJson("/api/payables/{$a->id}/pay", [
            'pagos' => [['tipo' => 'efectivo', 'valor' => 15], ['tipo' => 'transferencia', 'valor' => 100]],
        ])->assertOk();
        $this->assertAsientoPago($a, 'Pago compra', [['2.1.11', 115, 0], ['1.1.01', 0, 15], ['1.1.02', 0, 100]]);

        $b = $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);
        $this->postJson("/api/payables/{$b->id}/pay", ['monto' => 115, 'forma_pago' => 'transferencia'])->assertOk();
        $this->assertAsientoPago($b, 'Pago compra', [['2.1.11', 115, 0], ['1.1.02', 0, 115]]);

        $c = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $this->postJson("/api/payables/{$c->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 115]]])->assertOk();
        $this->assertAsientoPago($c, 'Pago compra', [['2.1.01', 115, 0], ['1.1.01', 0, 115]]);
    }

    public function test_pago_multiple_separa_cada_cuenta_por_pagar(): void
    {
        $normal = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);        // 115
        $relacionada = $this->comprar($this->proveedorRel, [['SERV-1', 1, 200]]); // 230

        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id, 'forma_pago' => 'efectivo',
            'pagos' => [['purchase_id' => $normal->id, 'monto' => 115], ['purchase_id' => $relacionada->id, 'monto' => 230]],
        ])->assertOk();

        $asiento = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Pago múltiple%')->firstOrFail();
        $this->assertLineas($asiento, [['2.1.01', 115, 0], ['2.1.11', 230, 0], ['1.1.01', 0, 345]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_retencion_a_proveedor_relacionado_debita_cxp_relacionadas(): void
    {
        $compra = $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);

        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100], ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15]],
        ])->assertCreated();

        $fila = \App\Models\Withholding::where('purchase_id', $compra->id)->orderBy('id')->firstOrFail();
        $asiento = JournalEntry::where('origen_type', $fila->getMorphClass())->where('origen_id', $fila->id)->firstOrFail();
        $this->assertLineas($asiento, [['2.1.11', 25, 0], ['2.1.09', 0, 15], ['2.1.10', 0, 10]]);
        $this->assertEquals(90.0, (float) $compra->fresh()->saldo_pendiente);

        // Y el pago del saldo que queda sigue en la misma cuenta
        $this->postJson("/api/payables/{$compra->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 90]]])->assertOk();
        $this->assertAsientoPago($compra, 'Pago compra', [['2.1.11', 90, 0], ['1.1.01', 0, 90]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ cuenta contable por defecto del proveedor

    public function test_los_servicios_del_proveedor_van_a_su_cuenta_por_defecto_y_los_bienes_a_inventario(): void
    {
        $cuenta = $this->cuentaGasto('5.1.20', 'Servicios básicos');
        $this->proveedor->update(['cuenta_contable_id' => $cuenta->id]);

        $compra = $this->comprar($this->proveedor, [['MERC-1', 10, 5], ['SERV-1', 1, 40]]);

        $this->assertAsiento($compra, [
            ['1.1.05', 50, 0],   // el bien sigue en inventario
            ['5.1.20', 40, 0],   // el servicio va a la cuenta del proveedor
            ['1.1.04', 13.5, 0],
            ['2.1.01', 0, 103.5],
        ]);
        $this->assertNull(Account::where('company_id', $this->company->id)->where('codigo', '5.1.01')->first());
    }

    public function test_una_compra_solo_de_servicios_va_toda_a_la_cuenta_del_proveedor_y_otro_proveedor_sigue_en_compras(): void
    {
        $cuenta = $this->cuentaGasto('5.1.20', 'Servicios básicos');
        $this->proveedor->update(['cuenta_contable_id' => $cuenta->id]);

        $conCuenta = $this->comprar($this->proveedor, [['SERV-1', 2, 25]]);
        $sinCuenta = $this->comprar($this->proveedorRel, [['SERV-1', 2, 25]]);

        $this->assertAsiento($conCuenta, [['5.1.20', 50, 0], ['1.1.04', 7.5, 0], ['2.1.01', 0, 57.5]]);
        $this->assertAsiento($sinCuenta, [['5.1.01', 50, 0], ['1.1.04', 7.5, 0], ['2.1.11', 0, 57.5]]);
    }

    public function test_si_la_cuenta_por_defecto_ya_no_sirve_la_compra_vuelve_a_compras(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajena = Account::create(['company_id' => $otra->id, 'codigo' => '5.1.20', 'nombre' => 'Ajena', 'tipo' => 'gasto']);
        // Un dato incoherente (cuenta de otra empresa) nunca debe terminar en el asiento
        $this->proveedor->forceFill(['cuenta_contable_id' => $ajena->id])->save();

        $compra = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);

        $this->assertAsiento($compra, [['5.1.01', 100, 0], ['1.1.04', 15, 0], ['2.1.01', 0, 115]]);
    }

    // ------------------------------------------------------------ chequeo y ATS

    public function test_chequeo_estricto_pasa_con_documentos_relacionados_y_no_relacionados_mezclados(): void
    {
        $f1 = $this->facturar($this->cliente);
        $f2 = $this->facturar($this->clienteRel);
        $p1 = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $p2 = $this->comprar($this->proveedorRel, [['SERV-1', 1, 200]]);

        $this->postJson("/api/receivables/{$f1->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 15]]])->assertOk();
        $this->postJson("/api/receivables/{$f2->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 40]]])->assertOk();
        $this->postJson("/api/payables/{$p1->id}/pay", ['pagos' => [['tipo' => 'transferencia', 'valor' => 20]]])->assertOk();
        $this->postJson("/api/payables/{$p2->id}/pay", ['pagos' => [['tipo' => 'transferencia', 'valor' => 30]]])->assertOk();

        // CxC: (115-15) + (115-40) = 175 entre 1.1.03 (100) y 1.1.09 (75); CxP: (115-20) + (230-30) = 295 entre 2.1.01 (95) y 2.1.11 (200)
        $this->chequeoEstricto()
            ->expectsOutputToContain('OK  Cuentas por cobrar (1.1.03 + 1.1.09): facturas pendientes − notas de crédito disponibles $175.00 = mayor $175.00')
            ->expectsOutputToContain('OK  Cuentas por pagar (2.1.01 + 2.1.11): compras pendientes $295.00 = mayor $295.00')
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);
    }

    public function test_chequeo_estricto_detecta_una_diferencia_en_la_cuenta_de_relacionadas(): void
    {
        $this->facturar($this->clienteRel);
        $p = $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);
        $this->chequeoEstricto()->assertExitCode(0);

        $p->increment('saldo_pendiente', 5);

        $this->chequeoEstricto()->expectsOutputToContain('DIFERENCIA  Cuentas por pagar')->assertExitCode(1);
    }

    public function test_el_ats_usa_la_marca_de_parte_relacionada_del_contacto(): void
    {
        $this->facturar($this->cliente);
        $this->facturar($this->clienteRel);
        $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $this->comprar($this->proveedorRel, [['SERV-1', 1, 100]]);

        $xml = $this->get('/api/tax/ats/xml?company_id='.$this->company->id.'&anio='.now()->year.'&mes='.now()->month)
            ->assertOk()->getContent();
        $doc = simplexml_load_string($xml);

        $ventas = array_map(fn ($v) => [(string) $v->idCliente, (string) $v->parteRelVtas], iterator_to_array($doc->ventas->detalleVentas, false));
        $this->assertEqualsCanonicalizing([['1700000001', 'NO'], ['1700000002', 'SI']], $ventas);

        $compras = array_map(fn ($c) => [(string) $c->idProv, (string) $c->parteRel], iterator_to_array($doc->compras->detalleCompras, false));
        $this->assertEqualsCanonicalizing([['1790011223001', 'NO'], ['1790011224001', 'SI']], $compras);
    }

    // ------------------------------------------------------------ consulta al SRI

    public function test_la_consulta_al_sri_sugiere_la_clase_solo_cuando_el_regimen_es_claro(): void
    {
        $casos = [
            'RISE' => 'rise',
            'RIMPE - NEGOCIO POPULAR' => 'negocio_popular',
            'RÉGIMEN RIMPE EMPRENDEDOR' => 'emprendedor',
            'GENERAL' => 'otros',
            'RIMPE' => null,      // sin decir si es emprendedor o negocio popular: no se adivina
            '' => null,
        ];
        $regimenActual = '';
        // Un solo fake: los fakes de Http se acumulan y gana el primero que coincide
        Http::fake(function () use (&$regimenActual) {
            return Http::response([[
                'razonSocial' => 'ACME SA', 'nombreComercial' => 'ACME', 'estadoContribuyenteRuc' => 'ACTIVO',
                'obligadoLlevarContabilidad' => 'NO', 'regimen' => $regimenActual, 'tipoContribuyente' => 'PERSONA NATURAL',
            ]], 200);
        });

        foreach ($casos as $regimen => $esperada) {
            $regimenActual = (string) $regimen;

            $r = $this->getJson('/api/sri/consulta?identificacion=1790011223001')->assertOk();
            $this->assertTrue($r->json('encontrado'));
            $this->assertSame($esperada, $r->json('clase_contribuyente_sugerida'), "Régimen: '$regimen'");
        }
    }

    // ------------------------------------------------------------ helpers

    private function contacto(string $identificacion, string $nombre, array $extra = []): Contact
    {
        return Contact::create($extra + [
            'company_id' => $this->company->id, 'tipo_identificacion' => '05',
            'identificacion' => $identificacion, 'razon_social' => $nombre,
        ]);
    }

    private function cuentaGasto(string $codigo, string $nombre): Account
    {
        return Account::create(['company_id' => $this->company->id, 'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'gasto']);
    }

    /** Factura a crédito de un servicio: $100 + IVA 15 = $115. */
    private function facturar(Contact $cliente, float $base = 100): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** @param array<int,array{0:string,1:float|int,2:float|int}> $lineas [codigo, cantidad, precio] */
    private function comprar(Contact $proveedor, array $lineas): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => now()->toDateString(),
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    /** El asiento que genera el propio documento (venta o compra): exactamente uno, con esas líneas. */
    private function assertAsiento(Model $origen, array $esperado): void
    {
        $asientos = JournalEntry::where('origen_type', $origen->getMorphClass())->where('origen_id', $origen->getKey())->get();
        $this->assertCount(1, $asientos, 'Se esperaba exactamente un asiento para '.class_basename($origen).' #'.$origen->getKey());
        $this->assertLineas($asientos->first(), $esperado);
    }

    /** El asiento de cobro/pago de un documento (el que empieza con ese concepto). */
    private function assertAsientoPago(Model $origen, string $concepto, array $esperado): void
    {
        $asientos = JournalEntry::where('origen_type', $origen->getMorphClass())->where('origen_id', $origen->getKey())
            ->where('concepto', 'like', $concepto.'%')->get();
        $this->assertCount(1, $asientos, "Se esperaba un asiento '$concepto' para ".class_basename($origen).' #'.$origen->getKey());
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
