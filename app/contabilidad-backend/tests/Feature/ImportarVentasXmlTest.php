<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.7c: registrar las ventas emitidas desde el portal del SRI subiendo el XML autorizado de cada factura.
 * Es un documento REAL: la clave de acceso, el número de autorización y los totales salen del XML, nunca se
 * inventan. No toca inventario (sin kárdex ni costo de ventas). Asiento: Debe CxC / Haber Ventas e IVA.
 */
class ImportarVentasXmlTest extends TestCase
{
    use RefreshDatabase;

    private const RUC_EMPRESA = '1791234567001';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => self::RUC_EMPRESA, 'razon_social' => 'Empresa Ventas XML SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'secuencial' => 7,
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_importa_una_factura_autorizada_con_sus_lineas_totales_asiento_y_chequeo_estricto(): void
    {
        $producto = Product::create(['company_id' => $this->company->id, 'codigo' => 'PROD-1', 'descripcion' => 'Producto uno',
            'tipo' => 'bien', 'stock' => 10, 'costo_promedio' => 5, 'precio' => 50]);
        $clave = $this->clave('000000045');

        $resp = $this->subir($this->xmlAutorizado($clave))->assertCreated();

        $this->assertTrue($resp->json('ok'));
        $this->assertCount(1, $resp->json('importadas'));
        $this->assertSame('001-002-000000045', $resp->json('importadas.0.numero'));
        $this->assertSame([], $resp->json('rechazadas'));

        // La factura: totales, saldo y marca de documento ya emitido
        $factura = Invoice::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('001-002-000000045', $factura->numero);
        $this->assertSame('factura', $factura->tipo_comprobante);
        $this->assertSame('emitida', $factura->estado);
        $this->assertSame('credito', $factura->forma_pago);
        $this->assertSame('2026-09-10', $factura->fecha_emision->format('Y-m-d'));
        $this->assertEquals(150.0, (float) $factura->total_sin_impuestos);
        $this->assertEquals(22.5, (float) $factura->total_impuesto);
        $this->assertEquals(172.5, (float) $factura->importe_total);
        $this->assertEquals(172.5, (float) $factura->saldo_pendiente);

        // Líneas: la que coincide con un producto lo enlaza por su código principal; la otra queda sin enlace
        $this->assertCount(2, $factura->items);
        $this->assertSame('PROD-1', $factura->items[0]['codigo_principal']);
        $this->assertEquals(2, $factura->items[0]['cantidad']);
        $this->assertEquals(50, $factura->items[0]['precio_unitario']);
        $this->assertEquals(15, $factura->items[0]['tarifa']);
        $this->assertSame($producto->id, $factura->items[0]['product_id']);
        $this->assertSame('XYZ-99', $factura->items[1]['codigo_principal']);
        $this->assertNull($factura->items[1]['product_id']);

        // Documento SRI ya autorizado, con los datos del XML (no inventados)
        $doc = SriDocument::where('documentable_id', $factura->id)->firstOrFail();
        $this->assertSame($factura->getMorphClass(), $doc->documentable_type);
        $this->assertSame($clave, $doc->clave_acceso);
        $this->assertSame('AUTORIZADO', $doc->estado);
        $this->assertSame($clave, $doc->numero_autorizacion);
        $this->assertSame('factura', $doc->tipo_comprobante);
        $this->assertEquals(2, (int) $doc->ambiente);
        $this->assertStringContainsString('<claveAcceso>'.$clave.'</claveAcceso>', (string) $doc->xml);

        // El cliente se crea por su identificación
        $cliente = Contact::where('company_id', $this->company->id)->where('identificacion', '1790099887001')->firstOrFail();
        $this->assertSame($cliente->id, $factura->contact_id);
        $this->assertSame('CLIENTE DEL PORTAL S.A.', $cliente->razon_social);
        $this->assertTrue((bool) $cliente->es_cliente);
        $this->assertSame('04', $cliente->tipo_identificacion);

        // El asiento de venta normal, sin costo de ventas
        $asiento = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->firstOrFail();
        $this->assertSame('Venta factura 001-002-000000045', $asiento->concepto);
        $this->assertLineas($asiento, [['1.1.03', 172.5, 0], ['4.1.01', 0, 150], ['2.1.02', 0, 22.5]]);

        // Nunca mueve inventario ni la secuencia de emisión de la empresa
        $this->assertSame(0, InventoryMovement::count());
        $this->assertEquals(10, (float) $producto->fresh()->stock);
        $this->assertSame(7, (int) $this->company->fresh()->secuencial);

        // Aparece en el listado de facturas y la cartera. T3.4: la autorizada SÍ se anula en el sistema (antes daba 422), pero el SRI
        // sigue considerándola válida: la respuesta pide anularla también en el portal SRI en línea.
        $this->assertContains('001-002-000000045', array_column($this->getJson('/api/invoices?company_id='.$this->company->id)->assertOk()->json(), 'numero'));
        $this->assertEquals(172.5, $this->getJson('/api/receivables?company_id='.$this->company->id)->json('total'));
        $anulada = $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();
        $this->assertTrue($anulada->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('portal SRI en línea', $anulada->json('aviso_sri'));
        $this->assertSame('anulado', $factura->fresh()->estado);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_un_cliente_parte_relacionada_existente_se_reutiliza_y_la_cxc_es_la_de_relacionadas(): void
    {
        $existente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790099887001',
            'razon_social' => 'Nombre ya guardado', 'es_cliente' => false, 'es_proveedor' => true, 'parte_relacionada' => true]);

        $this->subir($this->xmlAutorizado($this->clave('000000046'), '000000046'))->assertCreated();

        $this->assertSame(1, Contact::where('company_id', $this->company->id)->count(), 'No debe duplicar el contacto');
        $existente->refresh();
        $this->assertSame('Nombre ya guardado', $existente->razon_social);
        $this->assertTrue((bool) $existente->es_cliente);             // ahora también es cliente
        $this->assertTrue((bool) $existente->es_proveedor);

        $factura = Invoice::firstOrFail();
        $asiento = JournalEntry::where('origen_id', $factura->id)->where('origen_type', $factura->getMorphClass())->firstOrFail();
        $this->assertLineas($asiento, [['1.1.09', 172.5, 0], ['4.1.01', 0, 150], ['2.1.02', 0, 22.5]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_una_clave_de_acceso_repetida_se_rechaza(): void
    {
        $xml = $this->xmlAutorizado($this->clave('000000047'), '000000047');
        $this->subir($xml)->assertCreated();
        $asientos = JournalEntry::count();

        $r = $this->subir($xml)->assertStatus(422);
        $this->assertFalse($r->json('ok'));
        $this->assertSame([], $r->json('importadas'));
        $this->assertStringContainsString('ya está registrada', $r->json('rechazadas.0.motivo'));
        $this->assertStringContainsString('001-002-000000047', $r->json('rechazadas.0.motivo'));
        $this->assertSame('ventas.xml', $r->json('rechazadas.0.archivo'));

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, SriDocument::count());
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertEquals(172.5, (float) Invoice::firstOrFail()->saldo_pendiente);
    }

    public function test_una_factura_de_otro_ruc_se_rechaza(): void
    {
        $r = $this->subir($this->xmlAutorizado($this->clave('000000048', '1790000000001'), '000000048', '1790000000001'))->assertStatus(422);

        $this->assertStringContainsString('1790000000001', $r->json('rechazadas.0.motivo'));
        $this->assertStringContainsString(self::RUC_EMPRESA, $r->json('rechazadas.0.motivo'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, SriDocument::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, Contact::count(), 'No debe crear el cliente de una factura rechazada');
    }

    public function test_sin_autorizacion_del_sri_no_se_registra(): void
    {
        $sinSobre = $this->facturaXml($this->clave('000000049'), '000000049');                    // factura suelta, sin autorización
        $r = $this->subir($sinSobre)->assertStatus(422);
        $this->assertStringContainsString('autorización', $r->json('rechazadas.0.motivo'));

        $noAutorizada = str_replace('<estado>AUTORIZADO</estado>', '<estado>NO AUTORIZADO</estado>', $this->xmlAutorizado($this->clave('000000050'), '000000050'));
        $this->subir($noAutorizada)->assertStatus(422);

        $this->assertSame(0, Invoice::count());
    }

    public function test_un_xml_que_no_es_factura_o_con_totales_que_no_cuadran_se_rechaza(): void
    {
        $this->subir('<comprobanteRetencion><infoTributaria><ruc>'.self::RUC_EMPRESA.'</ruc></infoTributaria></comprobanteRetencion>')->assertStatus(422);
        $this->subir('esto no es xml')->assertStatus(422);

        $descuadrada = str_replace('<importeTotal>172.50</importeTotal>', '<importeTotal>999.00</importeTotal>', $this->xmlAutorizado($this->clave('000000051'), '000000051'));
        $r = $this->subir($descuadrada)->assertStatus(422);
        $this->assertStringContainsString('no cuadran', $r->json('rechazadas.0.motivo'));

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_varios_archivos_importan_los_buenos_y_listan_los_rechazados(): void
    {
        $buena = $this->xmlAutorizado($this->clave('000000052'), '000000052');
        $ajena = $this->xmlAutorizado($this->clave('000000053', '1790000000001'), '000000053', '1790000000001');

        $r = $this->post('/api/sri/importar-ventas-xml', [
            'company_id' => $this->company->id,
            'xml_files' => [
                UploadedFile::fake()->createWithContent('buena.xml', $buena),
                UploadedFile::fake()->createWithContent('ajena.xml', $ajena),
                UploadedFile::fake()->createWithContent('repetida.xml', $buena),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertCount(1, $r->json('importadas'));
        $this->assertSame('buena.xml', $r->json('importadas.0.archivo'));
        $this->assertEqualsCanonicalizing(['ajena.xml', 'repetida.xml'], array_column($r->json('rechazadas'), 'archivo'));
        $this->assertSame(1, Invoice::count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ helpers

    private function subir(string $xml)
    {
        return $this->post('/api/sri/importar-ventas-xml', [
            'company_id' => $this->company->id,
            'xml_file' => UploadedFile::fake()->createWithContent('ventas.xml', $xml),
        ], ['Accept' => 'application/json']);
    }

    /** Clave de acceso de una factura (01) de 49 dígitos; la serie 001002 va dentro. */
    private function clave(string $secuencial, string $ruc = self::RUC_EMPRESA): string
    {
        $clave = '10092026'.'01'.$ruc.'2'.'001002'.$secuencial.'12345678'.'1'.'3';
        $this->assertSame(49, strlen($clave));

        return $clave;
    }

    /** Lo que descarga el portal: el sobre de autorización con la factura en CDATA. */
    private function xmlAutorizado(string $clave, string $secuencial = '000000045', string $ruc = self::RUC_EMPRESA): string
    {
        $factura = $this->facturaXml($clave, $secuencial, $ruc);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<autorizacion>
  <estado>AUTORIZADO</estado>
  <numeroAutorizacion>$clave</numeroAutorizacion>
  <fechaAutorizacion>2026-09-10T10:01:11-05:00</fechaAutorizacion>
  <ambiente>PRODUCCION</ambiente>
  <comprobante><![CDATA[$factura]]></comprobante>
</autorizacion>
XML;
    }

    /** Factura: 2 x 50 (PROD-1) + 1 x 50 (XYZ-99) = 150 + IVA 15 % 22.50 = 172.50. */
    private function facturaXml(string $clave, string $secuencial, string $ruc = self::RUC_EMPRESA): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<factura id="comprobante" version="1.0.0">
  <infoTributaria>
    <ambiente>2</ambiente><tipoEmision>1</tipoEmision>
    <razonSocial>Empresa Ventas XML SA</razonSocial><nombreComercial>Ventas XML</nombreComercial>
    <ruc>$ruc</ruc><claveAcceso>$clave</claveAcceso><codDoc>01</codDoc>
    <estab>001</estab><ptoEmi>002</ptoEmi><secuencial>$secuencial</secuencial>
    <dirMatriz>Av. Test 123</dirMatriz>
  </infoTributaria>
  <infoFactura>
    <fechaEmision>10/09/2026</fechaEmision>
    <dirEstablecimiento>Av. Test 123</dirEstablecimiento>
    <obligadoContabilidad>SI</obligadoContabilidad>
    <tipoIdentificacionComprador>04</tipoIdentificacionComprador>
    <razonSocialComprador>CLIENTE DEL PORTAL S.A.</razonSocialComprador>
    <identificacionComprador>1790099887001</identificacionComprador>
    <direccionComprador>Calle Cliente 45</direccionComprador>
    <totalSinImpuestos>150.00</totalSinImpuestos>
    <totalDescuento>0.00</totalDescuento>
    <totalConImpuestos>
      <totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>150.00</baseImponible><valor>22.50</valor></totalImpuesto>
    </totalConImpuestos>
    <propina>0.00</propina>
    <importeTotal>172.50</importeTotal>
    <moneda>DOLAR</moneda>
    <pagos><pago><formaPago>20</formaPago><total>172.50</total></pago></pagos>
  </infoFactura>
  <detalles>
    <detalle>
      <codigoPrincipal>PROD-1</codigoPrincipal><descripcion>Producto uno</descripcion>
      <cantidad>2.000000</cantidad><precioUnitario>50.000000</precioUnitario><descuento>0.00</descuento>
      <precioTotalSinImpuesto>100.00</precioTotalSinImpuesto>
      <impuestos><impuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><tarifa>15.00</tarifa><baseImponible>100.00</baseImponible><valor>15.00</valor></impuesto></impuestos>
    </detalle>
    <detalle>
      <codigoPrincipal>XYZ-99</codigoPrincipal><descripcion>Servicio sin ficha</descripcion>
      <cantidad>1.000000</cantidad><precioUnitario>50.000000</precioUnitario><descuento>0.00</descuento>
      <precioTotalSinImpuesto>50.00</precioTotalSinImpuesto>
      <impuestos><impuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><tarifa>15.00</tarifa><baseImponible>50.00</baseImponible><valor>7.50</valor></impuesto></impuestos>
    </detalle>
  </detalles>
</factura>
XML;
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
    }
}
