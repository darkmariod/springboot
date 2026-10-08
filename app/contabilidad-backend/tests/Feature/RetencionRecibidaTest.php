<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Withholding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.7a: retención RECIBIDA (XML que el cliente le entrega a la empresa).
 *   - Se acredita la cuenta por cobrar en que nació la factura (1.1.03, o 1.1.09 si el cliente es parte relacionada).
 *   - La fecha de la retención es la de emisión del XML, no la de hoy.
 *   - El mismo comprobante (clave de acceso) no se registra dos veces ni descuenta dos veces la factura.
 */
class RetencionRecibidaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $clienteRel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Retencion Recibida SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Cliente Normal SA',
        ]);
        $this->clienteRel = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011224001', 'razon_social' => 'Cliente Relacionado SA', 'parte_relacionada' => true,
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_retencion_de_una_factura_normal_debita_retenciones_anticipadas_y_acredita_cxc(): void
    {
        $factura = $this->facturar($this->cliente);                      // 115
        $clave = $this->clave('15092026', '1');

        $this->importar($this->xmlRetencion($factura->numero, 11.50, $clave, '15/09/2026'))->assertCreated();

        $retencion = Withholding::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($factura->id, $retencion->invoice_id);
        $this->assertEquals(11.5, (float) $retencion->total_retenido);
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertLineas($this->asientoRetencion(), [['1.1.06', 11.5, 0], ['1.1.03', 0, 11.5]]);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_retencion_de_una_factura_a_parte_relacionada_acredita_cxc_relacionadas(): void
    {
        $factura = $this->facturar($this->clienteRel);                   // nació en 1.1.09
        $this->importar($this->xmlRetencion($factura->numero, 11.50, $this->clave('15092026', '2'), '15/09/2026'))->assertCreated();

        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertLineas($this->asientoRetencion(), [['1.1.06', 11.5, 0], ['1.1.09', 0, 11.5]]);

        // Cartera contra el mayor: 1.1.03 en cero y 1.1.09 con el saldo de la factura
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_la_retencion_sin_factura_encontrada_conserva_la_cxc_normal_por_defecto(): void
    {
        $this->importar($this->xmlRetencion('001-001-000009999', 5, $this->clave('15092026', '3'), '15/09/2026'))->assertCreated();

        $retencion = Withholding::firstOrFail();
        $this->assertNull($retencion->invoice_id);
        $this->assertLineas($this->asientoRetencion(), [['1.1.06', 5, 0], ['1.1.03', 0, 5]]);
    }

    public function test_la_fecha_de_la_retencion_es_la_de_emision_del_xml(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->importar($this->xmlRetencion($factura->numero, 11.50, $this->clave('15092026', '4'), '15/09/2026'))->assertCreated();

        $this->assertSame('2026-09-15', substr((string) Withholding::firstOrFail()->fecha, 0, 10));
        $this->assertNotSame(now()->toDateString(), substr((string) Withholding::firstOrFail()->fecha, 0, 10));
    }

    public function test_sin_fecha_en_el_xml_la_retencion_queda_con_la_fecha_de_hoy(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->importar($this->xmlRetencion($factura->numero, 11.50, $this->clave('15092026', '5'), null))->assertCreated();

        $this->assertSame(now()->toDateString(), substr((string) Withholding::firstOrFail()->fecha, 0, 10));
    }

    public function test_el_mismo_comprobante_no_se_registra_dos_veces_ni_descuenta_la_factura_dos_veces(): void
    {
        $factura = $this->facturar($this->cliente);
        $xml = $this->xmlRetencion($factura->numero, 11.50, $this->clave('15092026', '6'), '15/09/2026');

        $this->importar($xml)->assertCreated();
        $asientos = JournalEntry::where('company_id', $this->company->id)->count();

        $this->importar($xml)->assertStatus(422)->assertJsonValidationErrors('xml');
        $mensaje = $this->importar($xml)->json('errors.xml.0');
        $this->assertStringContainsString('ya fue registrada', $mensaje);

        $this->assertSame(1, Withholding::count());
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame($asientos, JournalEntry::where('company_id', $this->company->id)->count(), 'No debe quedar un segundo asiento');
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_dos_retenciones_distintas_de_la_misma_factura_si_se_registran(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->importar($this->xmlRetencion($factura->numero, 10, $this->clave('15092026', '7'), '15/09/2026'))->assertCreated();
        $this->importar($this->xmlRetencion($factura->numero, 1.50, $this->clave('16092026', '8'), '16/09/2026', '000000002'))->assertCreated();

        $this->assertSame(2, Withholding::count());
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
    }

    // ------------------------------------------------------------ helpers

    private function importar(string $xml)
    {
        return $this->post('/api/withholdings/import', [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('retencion.xml', $xml),
        ], ['Accept' => 'application/json']);
    }

    /** Factura a crédito de un servicio: $100 + IVA 15 = $115. */
    private function facturar(Contact $cliente): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Clave de acceso de 49 dígitos de una retención (tipo 07) de otro emisor. */
    private function clave(string $fechaDdmmaaaa, string $marca): string
    {
        return $fechaDdmmaaaa.'07'.'1790011223001'.'2'.'001001'.str_pad($marca, 9, '0', STR_PAD_LEFT).'12345678'.'1'.'3';
    }

    private function xmlRetencion(string $numeroFactura, float $valor, string $clave, ?string $fechaEmision, string $secuencial = '000000001'): string
    {
        $sustento = str_replace('-', '', $numeroFactura);
        $fecha = $fechaEmision ? "<fechaEmision>$fechaEmision</fechaEmision>" : '';
        $v = number_format($valor, 2, '.', '');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<comprobanteRetencion id="comprobante" version="1.0.0">
  <infoTributaria>
    <ambiente>1</ambiente><tipoEmision>1</tipoEmision>
    <razonSocial>Cliente que retiene</razonSocial><ruc>1790011223001</ruc>
    <claveAcceso>$clave</claveAcceso><codDoc>07</codDoc>
    <estab>001</estab><ptoEmi>001</ptoEmi><secuencial>$secuencial</secuencial>
  </infoTributaria>
  <infoCompRetencion>
    $fecha
    <identificacionSujetoRetenido>1791234567001</identificacionSujetoRetenido>
  </infoCompRetencion>
  <impuestos>
    <impuesto>
      <codigo>1</codigo><codigoRetencion>303</codigoRetencion>
      <baseImponible>100.00</baseImponible><porcentajeRetener>10</porcentajeRetener>
      <valorRetenido>$v</valorRetenido>
      <codDocSustento>01</codDocSustento><numDocSustento>$sustento</numDocSustento>
    </impuesto>
  </impuestos>
</comprobanteRetencion>
XML;
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    private function asientoRetencion(): JournalEntry
    {
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Retención recibida%')->get();
        $this->assertCount(1, $asientos);

        return $asientos->first();
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
    }
}
