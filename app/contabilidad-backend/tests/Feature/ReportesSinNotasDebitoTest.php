<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.8 (parte de reportes): las notas de débito (del SRI y las internas) viven en la tabla de facturas, pero NO son
 * ventas. El ATS, el 104, el panel y los centros de costo solo suman facturas: con una factura y dos notas de débito
 * (una SRI y una interna) cada reporte da exactamente lo mismo que con la factura sola.
 */
class ReportesSinNotasDebitoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Invoice $factura;
    private CostCenter $centro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Reportes Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->centro = CostCenter::create(['company_id' => $this->company->id, 'codigo' => 'CC1', 'nombre' => 'Centro uno']);
        Sanctum::actingAs(User::factory()->create());

        // Una factura de $100 + IVA 15% = $115 a crédito, imputada al centro CC1
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();
        $this->factura = Invoice::where('company_id', $this->company->id)->firstOrFail();
        $this->factura->update(['cost_center_id' => $this->centro->id]);
    }

    /** Una nota de débito SRI ($20 + IVA 15% = $23) y una interna ($10 + IVA 15% = $11.50), las dos en el mismo mes y centro. */
    private function agregarNotasDeDebito(): void
    {
        $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'sri', 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => 20, 'tarifa' => 15]],
        ])->assertCreated();
        $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'interna',
            'motivos' => [['razon' => 'Cargo administrativo', 'valor' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        $notas = Invoice::where('company_id', $this->company->id)->whereIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO)->get();
        $this->assertCount(2, $notas);
        // Si en algún momento una nota llega con centro de costo, tampoco debe contar
        $notas->each->update(['cost_center_id' => $this->centro->id]);
    }

    private function mes(): array
    {
        return ['anio' => (int) now()->format('Y'), 'mes' => (int) now()->format('n')];
    }

    // ------------------------------------------------------------ formulario 104

    public function test_el_formulario_104_no_suma_notas_de_debito(): void
    {
        $this->agregarNotasDeDebito();

        $r = $this->getJson('/api/taxes/formulario104?'.http_build_query([
            'company_id' => $this->company->id, 'desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->endOfMonth()->toDateString(),
        ]))->assertOk();

        $this->assertEquals(115.0, $r->json('ventas.total_ventas'));
        $this->assertEquals(100.0, $r->json('ventas.ventas_12'));
        $this->assertEquals(0.0, $r->json('ventas.ventas_0'));
        $this->assertEquals(15.0, $r->json('ventas.iva_cobrado'));
        $this->assertSame(1, $r->json('resumen.total_facturas'));
        $this->assertEquals(15.0, $r->json('saldo_iva'));
    }

    public function test_el_ats_anual_no_suma_notas_de_debito(): void
    {
        $this->agregarNotasDeDebito();

        $r = $this->getJson('/api/taxes/ats?'.http_build_query(['company_id' => $this->company->id, 'anio' => $this->mes()['anio']]))->assertOk();
        $mes = $r->json('ventas_mensuales.'.$this->mes()['mes']);

        $this->assertEquals(115.0, $mes['total_ventas']);
        $this->assertEquals(100.0, $mes['ventas_12']);
        $this->assertEquals(15.0, $mes['iva_cobrado']);
        $this->assertSame(1, $mes['num_facturas']);
        $this->assertEquals(115.0, $r->json('resumen_anual.total_ventas'));
        $this->assertEquals(15.0, $r->json('resumen_anual.total_iva_cobrado'));
    }

    // ------------------------------------------------------------ ATS (XML del SRI)

    public function test_el_xml_del_ats_solo_lleva_la_factura_y_ninguna_nota_de_debito(): void
    {
        $this->agregarNotasDeDebito();
        // Una nota anulada tampoco aparece entre los comprobantes anulados de las ventas
        $sri = Invoice::where('tipo_comprobante', 'nota_debito')->firstOrFail();
        $this->postJson("/api/notas-debito/{$sri->id}/anular")->assertOk();

        $xml = $this->getAts();

        $this->assertSame('100.00', (string) $xml->totalVentas);
        $this->assertCount(1, $xml->ventas->detalleVentas);
        $this->assertSame('100.00', (string) $xml->ventas->detalleVentas->baseImpGrav);
        $this->assertSame('15.00', (string) $xml->ventas->detalleVentas->montoIva);
        $this->assertCount(0, $xml->ventasAnuladas->detalleAnulados, 'Una nota de débito anulada no es una venta anulada');
    }

    public function test_el_xml_del_ats_sigue_declarando_una_factura_anulada(): void
    {
        $this->agregarNotasDeDebito();
        // Una segunda factura que se anula: sigue saliendo en ventas anuladas, como siempre
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 50, 'tarifa' => 0]],
        ])->assertCreated();
        $segunda = Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
        $this->postJson("/api/invoices/{$segunda->id}/anular")->assertOk();

        $xml = $this->getAts();

        $this->assertSame('100.00', (string) $xml->totalVentas);
        $this->assertCount(1, $xml->ventas->detalleVentas);
        $this->assertCount(1, $xml->ventasAnuladas->detalleAnulados);
    }

    // ------------------------------------------------------------ panel (Inicio)

    public function test_el_panel_no_cuenta_notas_de_debito_como_ventas(): void
    {
        $this->agregarNotasDeDebito();

        $r = $this->getJson('/api/dashboard/resumen?company_id='.$this->company->id)->assertOk();

        $this->assertEquals(115.0, $r->json('ventas_mes'));
        $this->assertEquals(115.0, $r->json('ventas_serie.6.total'), 'La venta de hoy es solo la factura');
        $this->assertCount(1, $r->json('documentos'));
        $this->assertSame($this->factura->numero, $r->json('documentos.0.numero'));
        // La deuda de las notas vive en la factura: lo que se cobra es 115 + 23 + 11.50
        $this->assertEquals(149.5, $r->json('por_cobrar'));
        $this->assertSame(1, $r->json('facturas_por_cobrar'));
    }

    // ------------------------------------------------------------ centros de costo

    public function test_los_centros_de_costo_no_cuentan_notas_de_debito_como_ingresos(): void
    {
        $this->agregarNotasDeDebito();
        // Y una nota sin centro tampoco engorda "sin asignar"
        Invoice::where('tipo_comprobante', 'nota_debito_interna')->update(['cost_center_id' => null]);

        $r = $this->getJson('/api/cost-centers/resultados?company_id='.$this->company->id)->assertOk();

        $this->assertEquals(115.0, $r->json('centros.0.ingresos'));
        $this->assertEquals(0.0, $r->json('sin_asignar.ingresos'));
        $this->assertEquals(115.0, $r->json('totales.ingresos'));
    }

    // ------------------------------------------------------------ cómo se ve sin notas (referencia)

    public function test_sin_notas_de_debito_los_numeros_son_los_mismos(): void
    {
        $r = $this->getJson('/api/taxes/formulario104?'.http_build_query([
            'company_id' => $this->company->id, 'desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->endOfMonth()->toDateString(),
        ]))->assertOk();
        $this->assertEquals(115.0, $r->json('ventas.total_ventas'));
        $this->assertEquals(15.0, $r->json('ventas.iva_cobrado'));

        $panel = $this->getJson('/api/dashboard/resumen?company_id='.$this->company->id)->assertOk();
        $this->assertEquals(115.0, $panel->json('ventas_mes'));
        $this->assertEquals(115.0, $panel->json('por_cobrar'));

        $this->assertSame('100.00', (string) $this->getAts()->totalVentas);
        $centros = $this->getJson('/api/cost-centers/resultados?company_id='.$this->company->id)->assertOk();
        $this->assertEquals(115.0, $centros->json('totales.ingresos'));
    }

    private function getAts(): \SimpleXMLElement
    {
        $resp = $this->get('/api/tax/ats/xml?'.http_build_query(['company_id' => $this->company->id] + $this->mes()))->assertOk();

        return simplexml_load_string($resp->getContent());
    }
}
