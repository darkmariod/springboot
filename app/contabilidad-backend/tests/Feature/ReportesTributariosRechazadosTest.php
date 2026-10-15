<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.9c: el formulario 104 y el ATS (resumen anual y XML) usan el mismo criterio de ventas que el panel y los reportes:
 * una factura que el SRI RECHAZÓ no es una venta (nunca fue autorizada). Las generadas, firmadas, enviadas y autorizadas
 * siguen contando: son documentos válidos que van a ser autorizados.
 *
 *   A $100 + IVA 15% = $115  autorizada
 *   B  $50 + IVA 15% = $57.50 generada (sin certificado)
 *   C $200 + IVA 15% = $230  NO AUTORIZADO por el SRI
 */
class ReportesTributariosRechazadosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Invoice $a;
    private Invoice $b;
    private Invoice $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Tributaria Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());

        foreach ([100, 50, 200] as $base) {
            $this->postJson('/api/invoices', [
                'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
                'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
            ])->assertCreated();
        }
        [$this->a, $this->b, $this->c] = Invoice::where('company_id', $this->company->id)->orderBy('id')->get()->all();

        $this->estadoSri($this->a, 'AUTORIZADO');
        $this->estadoSri($this->b, 'GENERADO');
        $this->estadoSri($this->c, 'NO AUTORIZADO');
    }

    public function test_el_formulario_104_no_suma_la_factura_rechazada_por_el_sri(): void
    {
        $r = $this->formulario104();

        $this->assertEquals(172.5, $r->json('ventas.total_ventas'));     // 115 + 57.50, sin los 230 de la rechazada
        $this->assertEquals(150.0, $r->json('ventas.ventas_12'));
        $this->assertEquals(22.5, $r->json('ventas.iva_cobrado'));
        $this->assertSame(2, $r->json('resumen.total_facturas'));
        $this->assertEquals(22.5, $r->json('saldo_iva'));
    }

    public function test_el_formulario_104_sigue_contando_generadas_firmadas_enviadas_y_autorizadas(): void
    {
        foreach (['GENERADO', 'FIRMADO', 'ENVIADO', 'RECIBIDA', 'AUTORIZADO', 'PENDIENTE'] as $estado) {
            SriDocument::where('documentable_id', $this->c->id)->update(['estado' => $estado]);

            $r = $this->formulario104();
            $this->assertEquals(402.5, $r->json('ventas.total_ventas'), "Con el comprobante en $estado la factura sí es venta");
            $this->assertSame(3, $r->json('resumen.total_facturas'));
        }
    }

    public function test_el_formulario_104_excluye_todos_los_estados_de_rechazo(): void
    {
        foreach (SriDocument::ESTADOS_RECHAZADO as $estado) {
            SriDocument::where('documentable_id', $this->c->id)->update(['estado' => strtolower($estado)]);

            $this->assertEquals(172.5, $this->formulario104()->json('ventas.total_ventas'), "Estado $estado");
        }
    }

    public function test_el_formulario_104_no_suma_una_factura_anulada(): void
    {
        $this->b->update(['estado' => 'anulado']);     // la B de $57.50 se anula en el sistema

        $r = $this->formulario104();

        $this->assertEquals(115.0, $r->json('ventas.total_ventas'));     // solo la A: la B anulada y la C rechazada no cuentan
        $this->assertEquals(100.0, $r->json('ventas.ventas_12'));
        $this->assertEquals(15.0, $r->json('ventas.iva_cobrado'));
        $this->assertSame(1, $r->json('resumen.total_facturas'));
    }

    public function test_el_ats_anual_no_suma_una_factura_anulada(): void
    {
        $this->b->update(['estado' => 'anulado']);

        $r = $this->getJson('/api/taxes/ats?'.http_build_query(['company_id' => $this->company->id, 'anio' => (int) now()->format('Y')]))->assertOk();
        $mes = $r->json('ventas_mensuales.'.(int) now()->format('n'));

        $this->assertEquals(115.0, $mes['total_ventas']);
        $this->assertSame(1, $mes['num_facturas']);
        $this->assertEquals(115.0, $r->json('resumen_anual.total_ventas'));
    }

    public function test_el_ats_anual_no_suma_la_factura_rechazada(): void
    {
        $r = $this->getJson('/api/taxes/ats?'.http_build_query(['company_id' => $this->company->id, 'anio' => (int) now()->format('Y')]))->assertOk();
        $mes = $r->json('ventas_mensuales.'.(int) now()->format('n'));

        $this->assertEquals(172.5, $mes['total_ventas']);
        $this->assertEquals(150.0, $mes['ventas_12']);
        $this->assertEquals(22.5, $mes['iva_cobrado']);
        $this->assertSame(2, $mes['num_facturas']);
        $this->assertEquals(172.5, $r->json('resumen_anual.total_ventas'));
        $this->assertEquals(22.5, $r->json('resumen_anual.total_iva_cobrado'));
    }

    public function test_el_xml_del_ats_no_declara_la_factura_rechazada(): void
    {
        $xml = $this->atsXml();

        $this->assertSame('150.00', (string) $xml->totalVentas);
        $this->assertCount(2, $xml->ventas->detalleVentas);
        $bases = [];
        foreach ($xml->ventas->detalleVentas as $detalle) {
            $bases[] = (string) $detalle->baseImpGrav;
        }
        sort($bases);
        $this->assertSame(['50.00', '100.00'], $bases);
        $this->assertCount(0, $xml->ventasAnuladas->detalleAnulados);
    }

    public function test_el_xml_del_ats_tampoco_declara_como_anulada_una_factura_que_el_sri_rechazo(): void
    {
        // El SRI nunca la autorizó: no existe para el anexo, ni como venta ni como anulada
        $this->postJson("/api/invoices/{$this->c->id}/anular")->assertOk();
        // Una autorizada que se anula sí sigue saliendo entre las anuladas, como siempre
        $this->postJson("/api/invoices/{$this->a->id}/anular")->assertOk();

        $xml = $this->atsXml();

        $this->assertSame('50.00', (string) $xml->totalVentas);
        $this->assertCount(1, $xml->ventas->detalleVentas);
        $this->assertCount(1, $xml->ventasAnuladas->detalleAnulados);
    }

    public function test_sin_facturas_rechazadas_los_reportes_cuentan_todo_como_siempre(): void
    {
        SriDocument::where('documentable_id', $this->c->id)->update(['estado' => 'AUTORIZADO']);

        $this->assertEquals(402.5, $this->formulario104()->json('ventas.total_ventas'));
        $this->assertSame('350.00', (string) $this->atsXml()->totalVentas);
    }

    // ------------------------------------------------------------- helpers

    private function estadoSri(Invoice $factura, string $estado): void
    {
        SriDocument::create([
            'company_id' => $this->company->id, 'documentable_type' => $factura->getMorphClass(), 'documentable_id' => $factura->id,
            'tipo_comprobante' => 'factura', 'clave_acceso' => 'CLAVE-'.$factura->id, 'xml' => '<x/>',
            'estado' => $estado, 'ambiente' => 1, 'fecha_emision' => now(),
        ]);
    }

    private function formulario104(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/taxes/formulario104?'.http_build_query([
            'company_id' => $this->company->id, 'desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->endOfMonth()->toDateString(),
        ]))->assertOk();
    }

    private function atsXml(): \SimpleXMLElement
    {
        $resp = $this->get('/api/tax/ats/xml?'.http_build_query([
            'company_id' => $this->company->id, 'anio' => (int) now()->format('Y'), 'mes' => (int) now()->format('n'),
        ]))->assertOk();

        return simplexml_load_string($resp->getContent());
    }
}
