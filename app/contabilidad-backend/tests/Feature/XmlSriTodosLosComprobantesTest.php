<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * F9-2: cada comprobante electrónico que el sistema emite pasa por la librería del SRI. Aquí se recorre el camino REAL de cada
 * controlador (guardar y emitir), con la librería de verdad pero sin firma ni red (la empresa no tiene certificado: el
 * comprobante queda "generado" con su XML), y se comprueba que:
 *   - el XML se genera sin un solo aviso de PHP en el código del sistema ni en la librería (antes: "Undefined property:
 *     stdClass::$fechaEmisionDocSustento" al emitir una nota de crédito);
 *   - lleva los nodos clave (clave de acceso, secuencial, documento que modifica, totales, impuestos);
 *   - el número que ve el usuario es el secuencial de la clave de acceso, y emitir no gasta otro número;
 *   - ningún campo adicional va vacío (el SRI rechaza el comprobante).
 * Lo que NO se puede probar aquí: la firma XAdES con el .p12 ni el envío y la autorización del SRI.
 */
class XmlSriTodosLosComprobantesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Distribuidora Andina SA', 'nombre_comercial' => 'Andina Express',
            'dir_matriz' => 'Av. Amazonas N24-15 y Colón, Quito', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
            'ambiente' => 1, 'obligado_contabilidad' => true,
        ]);
        // Sin correo ni teléfono a propósito: la librería escribe esos dos campos siempre y el SRI no acepta campos vacíos
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'María Fernanda Pazmiño',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test SA',
            'direccion' => 'Calle Los Pinos 45', 'email' => 'prov@test.com',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));
    }

    // ------------------------------------------------------------ ayudas

    /**
     * Corre la acción con un manejador propio de errores y exige que ni el código del sistema (app/) ni la librería del SRI
     * (liberias_sri/) emitan avisos, notas o deprecaciones. Laravel los convertiría en excepciones; aquí se registran para
     * que el mensaje diga exactamente cuál fue.
     */
    private function sinAvisos(callable $accion): mixed
    {
        $avisos = [];
        set_error_handler(function (int $no, string $msg, string $archivo, int $linea) use (&$avisos) {
            if (! (error_reporting() & $no)) {
                return false;
            }
            if (str_contains($archivo, '/liberias_sri/') || str_contains($archivo, '/contabilidad-backend/app/')) {
                $avisos[] = $msg.' ('.basename($archivo).':'.$linea.')';
            }

            return true;
        });
        try {
            $resultado = $accion();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $avisos, "La generación del XML emitió avisos de PHP:\n".implode("\n", $avisos));

        return $resultado;
    }

    private function xmlDe(SriDocument $doc): SimpleXMLElement
    {
        $xml = simplexml_load_string((string) $doc->xml);
        $this->assertNotFalse($xml, 'El XML generado no es un XML válido.');

        return $xml;
    }

    /** Clave de acceso de 49 dígitos coherente con el resto del XML: tipo de comprobante, RUC, serie y secuencial. */
    private function assertClave(SimpleXMLElement $xml, string $codDoc, SriDocument $doc, ?string $secuencialEsperado = null): void
    {
        $clave = (string) $xml->infoTributaria->claveAcceso;
        $this->assertMatchesRegularExpression('/^\d{49}$/', $clave);
        $this->assertSame($doc->clave_acceso, $clave, 'La clave de acceso guardada es la del XML');
        $this->assertSame($codDoc, (string) $xml->infoTributaria->codDoc);
        $this->assertSame($codDoc, substr($clave, 8, 2), 'El tipo de comprobante de la clave');
        $this->assertSame('1791234567001', substr($clave, 10, 13), 'El RUC de la clave');
        $this->assertSame('001001', substr($clave, 24, 6), 'La serie (establecimiento y punto) de la clave');
        $this->assertSame((string) $xml->infoTributaria->secuencial, substr($clave, 30, 9), 'El secuencial de la clave es el del XML');
        $this->assertMatchesRegularExpression('/^\d{9}$/', (string) $xml->infoTributaria->secuencial);
        if ($secuencialEsperado !== null) {
            $this->assertSame($secuencialEsperado, (string) $xml->infoTributaria->secuencial);
        }
    }

    /** La parte final del número 001-001-000000007 → 000000007. */
    private function secuencialDe(string $numero): string
    {
        return substr($numero, -9);
    }

    /** Los hijos repetidos de un nodo (todos los <impuesto>, no solo el último) como lista. */
    private function hijos(SimpleXMLElement $nodos): array
    {
        return iterator_to_array($nodos, false);
    }

    private function assertSinCamposVacios(SimpleXMLElement $xml): void
    {
        foreach ($xml->xpath('//infoAdicional/campoAdicional') as $campo) {
            $this->assertNotSame('', trim((string) $campo), 'campoAdicional "'.$campo['nombre'].'" va vacío y el SRI lo rechaza');
        }
    }

    private function facturar(?array $items = null, ?string $fechaEmision = null): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => $items ?? [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 2, 'precio_unitario' => 50, 'tarifa' => 15]],
        ])->assertCreated();
        $factura = Invoice::latest('id')->firstOrFail();
        if ($fechaEmision) {
            // La nota debe llevar la fecha de la FACTURA que modifica, no la de hoy
            $factura->update(['fecha_emision' => $fechaEmision]);
        }

        return $factura->fresh();
    }

    // ------------------------------------------------------------ factura

    public function test_la_factura_genera_su_xml_con_los_nodos_clave(): void
    {
        $this->sinAvisos(fn () => $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'efectivo',
            'items' => [
                ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio con IVA', 'cantidad' => 2, 'precio_unitario' => 50, 'tarifa' => 15, 'descuento' => 10],
                ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio sin IVA', 'cantidad' => 1, 'precio_unitario' => 20, 'tarifa' => 0],
            ],
        ])->assertCreated());

        $factura = Invoice::latest('id')->firstOrFail();
        $doc = $factura->sriDocument()->firstOrFail();
        $xml = $this->xmlDe($doc);

        $this->assertSame('generado', $doc->estado);
        $this->assertSame('factura', $xml->getName());
        $this->assertClave($xml, '01', $doc, $this->secuencialDe($factura->numero));
        $this->assertSame('001-001-000000001', $factura->numero);
        $this->assertSame(now()->format('d/m/Y'), (string) $xml->infoFactura->fechaEmision);
        $this->assertSame('SI', (string) $xml->infoFactura->obligadoContabilidad);
        $this->assertSame('110.00', (string) $xml->infoFactura->totalSinImpuestos);
        $this->assertSame('10.00', (string) $xml->infoFactura->totalDescuento);
        $this->assertSame('123.50', (string) $xml->infoFactura->importeTotal);
        $this->assertCount(2, $xml->infoFactura->totalConImpuestos->totalImpuesto);
        $this->assertCount(2, $xml->detalles->detalle);
        $this->assertSinCamposVacios($xml);
        $this->assertSame(2, (int) $this->company->fresh()->secuencial, 'Una factura gasta un solo secuencial');
    }

    // ------------------------------------------------------------ nota de crédito

    public function test_la_nota_de_credito_del_sri_genera_su_xml_con_el_documento_que_modifica(): void
    {
        $factura = $this->facturar(null, now()->subDays(3)->toDateTimeString());
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'sri',
            'motivo' => 'Devolución parcial de servicios',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 1, 'precio_unitario' => 40, 'tarifa' => 15]],
        ])->assertCreated();
        $nota = CreditNote::latest('id')->firstOrFail();
        $secuencialAntes = (int) $this->company->fresh()->secuencial;

        $resp = $this->sinAvisos(fn () => $this->postJson("/api/credit-notes/{$nota->id}/emit")->assertOk());

        $doc = SriDocument::where('tipo_comprobante', 'notaCredito')->firstOrFail();
        $this->assertSame($doc->clave_acceso, $resp->json('sri_document.clave_acceso'));
        $this->assertSame('generado', $doc->estado);
        // Sin certificado el documento NO salió al SRI: el mensaje no puede decir que sí.
        $this->assertStringNotContainsString('emitida al SRI', (string) $resp->json('mensaje'));
        $this->assertStringContainsString('certificado', (string) $resp->json('mensaje'));
        $this->assertStringContainsString($doc->clave_acceso, (string) $resp->json('mensaje'));
        $xml = $this->xmlDe($doc);
        $this->assertSame('notaCredito', $xml->getName());
        $nota = $nota->fresh();
        $this->assertClave($xml, '04', $doc, $this->secuencialDe($nota->numero));
        $this->assertSame($secuencialAntes + 1, (int) $this->company->fresh()->secuencial, 'Emitir una nota gasta un solo secuencial');

        $info = $xml->infoNotaCredito;
        $this->assertSame(now()->format('d/m/Y'), (string) $info->fechaEmision);
        $this->assertSame('SI', (string) $info->obligadoContabilidad);
        $this->assertSame('05', (string) $info->tipoIdentificacionComprador);
        $this->assertSame('1700000001', (string) $info->identificacionComprador);
        $this->assertSame('01', (string) $info->codDocModificado);
        $this->assertSame($factura->numero, (string) $info->numDocModificado);
        $this->assertSame(now()->subDays(3)->format('d/m/Y'), (string) $info->fechaEmisionDocSustento, 'Lleva la fecha de la factura, no la de hoy');
        $this->assertSame('40.00', (string) $info->totalSinImpuestos);
        $this->assertSame('46.00', (string) $info->valorModificacion);
        $this->assertSame('DOLAR', (string) $info->moneda);
        $this->assertSame('Devolución parcial de servicios', (string) $info->motivo);
        $this->assertCount(1, $info->totalConImpuestos->totalImpuesto);
        $imp = $info->totalConImpuestos->totalImpuesto;
        $this->assertSame(['2', '4', '40.00', '6.00'], [(string) $imp->codigo, (string) $imp->codigoPorcentaje, (string) $imp->baseImponible, (string) $imp->valor]);

        $this->assertCount(1, $xml->detalles->detalle);
        $det = $xml->detalles->detalle;
        $this->assertSame(['SERV-1', 'Servicio de instalación', '1.00', '40.00', '0.00', '40.00'], [
            (string) $det->codigoInterno, (string) $det->descripcion, (string) $det->cantidad, (string) $det->precioUnitario,
            (string) $det->descuento, (string) $det->precioTotalSinImpuesto,
        ]);
        $iva = $det->impuestos->impuesto;
        $this->assertSame(['2', '4', '15', '40.00', '6.00'], [(string) $iva->codigo, (string) $iva->codigoPorcentaje, (string) $iva->tarifa, (string) $iva->baseImponible, (string) $iva->valor]);
        $this->assertSinCamposVacios($xml);
    }

    public function test_una_nota_de_credito_por_un_valor_suelto_sin_lineas_tambien_genera_su_xml(): void
    {
        $factura = $this->facturar();
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'sri',
            'motivo' => 'Descuento comercial', 'importe_total' => 10,
        ])->assertCreated();
        $nota = CreditNote::latest('id')->firstOrFail();

        $this->sinAvisos(fn () => $this->postJson("/api/credit-notes/{$nota->id}/emit")->assertOk());

        $xml = $this->xmlDe(SriDocument::where('tipo_comprobante', 'notaCredito')->firstOrFail());
        $this->assertSame('10.00', (string) $xml->infoNotaCredito->totalSinImpuestos);
        $this->assertSame('10.00', (string) $xml->infoNotaCredito->valorModificacion);
        $this->assertSame($factura->numero, (string) $xml->infoNotaCredito->numDocModificado);
        $this->assertSame('Descuento comercial', (string) $xml->infoNotaCredito->motivo);
        // Un comprobante sin detalle no existe: el valor suelto sale como una línea al 0% con el motivo
        $this->assertCount(1, $xml->detalles->detalle);
        $this->assertSame('Descuento comercial', (string) $xml->detalles->detalle->descripcion);
        $this->assertSame('0', (string) $xml->detalles->detalle->impuestos->impuesto->codigoPorcentaje);
        $this->assertSame('10.00', (string) $xml->detalles->detalle->precioTotalSinImpuesto);
        $this->assertSame('0', (string) $xml->infoNotaCredito->totalConImpuestos->totalImpuesto->codigoPorcentaje);
        $this->assertSinCamposVacios($xml);
    }

    // ------------------------------------------------------------ nota de débito

    public function test_la_nota_de_debito_del_sri_usa_el_mismo_numero_en_pantalla_y_en_la_clave_de_acceso(): void
    {
        $factura = $this->facturar(null, now()->subDays(5)->toDateTimeString());
        $secuencialAntes = (int) $this->company->fresh()->secuencial;

        $store = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => 'sri', 'forma_pago' => '01',
            'motivos' => [
                ['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0],
                ['razon' => 'Gastos de cobranza', 'valor' => 10, 'tarifa' => 15],
            ],
        ])->assertCreated();
        $nd = Invoice::findOrFail($store->json('nota_debito.id'));
        $numeroEnPantalla = $nd->numero;
        $this->assertSame(sprintf('001-001-%09d', $secuencialAntes), $numeroEnPantalla);
        $secuencialTrasGuardar = (int) $this->company->fresh()->secuencial;
        $this->assertSame($secuencialAntes + 1, $secuencialTrasGuardar, 'Guardar la nota reserva su número');

        $resp = $this->sinAvisos(fn () => $this->postJson("/api/notas-debito/{$nd->id}/emit")->assertOk());

        $doc = SriDocument::where('tipo_comprobante', 'notaDebito')->firstOrFail();
        $xml = $this->xmlDe($doc);
        $this->assertSame('notaDebito', $xml->getName());
        $this->assertClave($xml, '05', $doc, $this->secuencialDe($numeroEnPantalla));
        $this->assertSame($doc->clave_acceso, $resp->json('sri_document.clave_acceso'));
        $this->assertSame($numeroEnPantalla, $nd->fresh()->numero, 'Emitir no cambia el número de la nota');
        $this->assertSame($secuencialTrasGuardar, (int) $this->company->fresh()->secuencial, 'Emitir no gasta otro número: ya se reservó al guardar');

        $info = $xml->infoNotaDebito;
        $this->assertSame('SI', (string) $info->obligadoContabilidad, 'La empresa lleva contabilidad');
        $this->assertSame('01', (string) $info->codDocModificado);
        $this->assertSame($factura->numero, (string) $info->numDocModificado);
        $this->assertSame(now()->subDays(5)->format('d/m/Y'), (string) $info->fechaEmisionDocSustento, 'Lleva la fecha de la factura, no la de la nota');
        $this->assertSame('15.00', (string) $info->totalSinImpuestos);
        $this->assertSame('16.50', (string) $info->valorTotal);
        $this->assertCount(2, $info->impuestos->impuesto);
        $porCodigo = collect($this->hijos($info->impuestos->impuesto))->mapWithKeys(fn ($i) => [(string) $i->codigoPorcentaje => [(string) $i->tarifa, (string) $i->baseImponible, (string) $i->valor]]);
        $this->assertSame(['0', '5.00', '0.00'], $porCodigo['0']);
        $this->assertSame(['15', '10.00', '1.50'], $porCodigo['4']);
        $pago = $info->pagos->pago;
        $this->assertSame(['01', '16.50'], [(string) $pago->formaPago, (string) $pago->total]);
        $this->assertCount(2, $xml->motivos->motivo);
        $this->assertSame('Intereses de mora', (string) $xml->motivos->motivo[0]->razon);
        $this->assertSame('5.00', (string) $xml->motivos->motivo[0]->valor);
        $this->assertSinCamposVacios($xml);
    }

    public function test_dos_notas_de_debito_seguidas_llevan_numeros_consecutivos_sin_huecos(): void
    {
        $factura = $this->facturar();
        $primera = $this->nuevaNotaDebito($factura);
        $segunda = $this->nuevaNotaDebito($factura);
        $this->sinAvisos(fn () => $this->postJson("/api/notas-debito/{$primera->id}/emit")->assertOk());
        $this->sinAvisos(fn () => $this->postJson("/api/notas-debito/{$segunda->id}/emit")->assertOk());

        $secuenciales = SriDocument::where('tipo_comprobante', 'notaDebito')->orderBy('id')->get()
            ->map(fn ($d) => (string) $this->xmlDe($d)->infoTributaria->secuencial)->all();
        $this->assertSame([$this->secuencialDe($primera->numero), $this->secuencialDe($segunda->numero)], $secuenciales);
        $this->assertSame((int) $this->secuencialDe($primera->numero) + 1, (int) $this->secuencialDe($segunda->numero));
    }

    private function nuevaNotaDebito(Invoice $factura): Invoice
    {
        $id = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => 'sri', 'forma_pago' => '01',
            'motivos' => [['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0]],
        ])->assertCreated()->json('nota_debito.id');

        return Invoice::findOrFail($id);
    }

    // ------------------------------------------------------------ retención

    public function test_la_retencion_emitida_genera_su_xml_con_las_lineas_de_renta_y_de_iva(): void
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000099', 'fecha_emision' => '2026-10-05',
            'items' => [['codigo_principal' => 'RET-BIEN', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();
        $compra = Purchase::where('company_id', $this->company->id)->firstOrFail();
        $secuencialAntes = (int) $this->company->fresh()->secuencial;

        $resp = $this->sinAvisos(fn () => $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100], ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15]],
        ])->assertCreated());

        $doc = SriDocument::where('tipo_comprobante', 'comprobanteRetencion')->firstOrFail();
        $xml = $this->xmlDe($doc);
        $this->assertSame('generado', $resp->json('sri_document.estado'));
        $this->assertSame('comprobanteRetencion', $xml->getName());
        $this->assertClave($xml, '07', $doc, $this->secuencialDe($resp->json('numero')));
        $this->assertSame(sprintf('001-001-%09d', $secuencialAntes), $resp->json('numero'));
        $this->assertSame($secuencialAntes + 1, (int) $this->company->fresh()->secuencial);

        $info = $xml->infoCompRetencion;
        $this->assertSame(now()->format('d/m/Y'), (string) $info->fechaEmision);
        $this->assertSame(now()->format('m/Y'), (string) $info->periodoFiscal);
        $this->assertSame('SI', (string) $info->obligadoContabilidad);
        $this->assertSame('1790011223001', (string) $info->identificacionSujetoRetenido);
        $this->assertCount(2, $xml->impuestos->impuesto);
        $filas = collect($this->hijos($xml->impuestos->impuesto))->map(fn ($i) => [(string) $i->codigo, (string) $i->codigoRetencion, (string) $i->baseImponible, (string) $i->porcentajeRetener, (string) $i->valorRetenido, (string) $i->codDocSustento, (string) $i->numDocSustento, (string) $i->fechaEmisionDocSustento])->all();
        $this->assertSame(['1', '303', '100.00', '10', '10.00', '01', '001001000000099', '05/10/2026'], $filas[0]);
        $this->assertSame(['2', '3', '15.00', '100', '15.00', '01', '001001000000099', '05/10/2026'], $filas[1]);
        $this->assertSinCamposVacios($xml);
    }

    // ------------------------------------------------------------ liquidación de compra

    public function test_la_liquidacion_de_compra_genera_su_xml_y_no_gasta_un_segundo_numero(): void
    {
        $store = $this->postJson('/api/liquidacion-compra', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'forma_pago' => '01',
            'items' => [
                ['codigo_principal' => 'LIQ-001', 'descripcion' => 'Artesanías', 'cantidad' => 2, 'precio_unitario' => 50, 'base_imponible' => 100, 'tarifa' => 15],
                ['codigo_principal' => 'LIQ-002', 'descripcion' => 'Semillas', 'cantidad' => 1, 'precio_unitario' => 20, 'base_imponible' => 20, 'tarifa' => 5],
                ['codigo_principal' => 'LIQ-003', 'descripcion' => 'Leña', 'cantidad' => 1, 'precio_unitario' => 10, 'base_imponible' => 10, 'tarifa' => 0],
            ],
            'total_sin_impuestos' => 130, 'total_impuesto' => 16, 'importe_total' => 146,
        ])->assertCreated();
        $compra = Purchase::findOrFail($store->json('purchase.id'));
        $numeroEnPantalla = $compra->numero;
        $secuencialTrasGuardar = (int) $this->company->fresh()->secuencial;
        $this->assertSame(2, $secuencialTrasGuardar);

        $resp = $this->sinAvisos(fn () => $this->postJson("/api/liquidacion-compra/{$compra->id}/emit")->assertOk());

        $doc = SriDocument::where('tipo_comprobante', 'liquidacionCompra')->firstOrFail();
        $xml = $this->xmlDe($doc);
        $this->assertSame($doc->clave_acceso, $resp->json('sri_document.clave_acceso'));
        $this->assertSame('liquidacionCompra', $xml->getName());
        $this->assertClave($xml, '03', $doc, $this->secuencialDe($numeroEnPantalla));
        $this->assertSame($secuencialTrasGuardar, (int) $this->company->fresh()->secuencial, 'Emitir no gasta otro número: ya se reservó al guardar');

        $info = $xml->infoLiquidacionCompra;
        $this->assertSame('SI', (string) $info->obligadoContabilidad);
        $this->assertSame('04', (string) $info->tipoIdentificacionProveedor);
        $this->assertSame('1790011223001', (string) $info->identificacionProveedor);
        $this->assertSame('130.00', (string) $info->totalSinImpuestos);
        $this->assertSame('146.00', (string) $info->importeTotal);
        // 15% = código 4, 5% = código 5 (no el 2, que es el 12%) y 0% = código 0
        $porCodigo = collect($this->hijos($info->totalConImpuestos->totalImpuesto))->mapWithKeys(fn ($i) => [(string) $i->codigoPorcentaje => [(string) $i->baseImponible, (string) $i->valor]]);
        $this->assertSame(['4' => ['100.00', '15.00'], '5' => ['20.00', '1.00'], '0' => ['10.00', '0.00']], $porCodigo->all());
        $this->assertSame(['01', '146.00'], [(string) $info->pagos->pago->formaPago, (string) $info->pagos->pago->total]);
        $this->assertCount(3, $xml->detalles->detalle);
        $this->assertSame('5', (string) $xml->detalles->detalle[1]->impuestos->impuesto->codigoPorcentaje);
        $this->assertSame('1.00', (string) $xml->detalles->detalle[1]->impuestos->impuesto->valor);
        $this->assertSinCamposVacios($xml);
    }

    // ------------------------------------------------------------ guía de remisión

    public function test_la_guia_de_remision_genera_su_xml_con_fecha_para_la_clave_y_el_numero_reservado(): void
    {
        $cuerpo = [
            'company_id' => $this->company->id, 'transportista_ruc' => '1790012345001', 'transportista_nombre' => 'Transportes Andinos',
            'transportista_placa' => 'PBA-1234', 'dir_partida' => 'Av. Amazonas N24-15, Quito',
            'fecha_ini_transporte' => now()->toDateString(), 'fecha_fin_transporte' => now()->addDay()->toDateString(),
            'destinatarios' => [[
                'identificacion' => '1700000001', 'razon_social' => 'María Fernanda Pazmiño', 'direccion' => 'Calle Los Pinos 45',
                'motivo_traslado' => 'Venta de mercadería',
                'detalles' => [['codigo_interno' => 'SERV-1', 'descripcion' => 'Caja de repuestos', 'cantidad' => 3]],
            ]],
        ];
        $store = $this->postJson('/api/guias-remision', $cuerpo)->assertCreated();
        $guia = SriDocument::findOrFail($store->json('guia.id'));
        $numeroEnPantalla = (string) $guia->numero_autorizacion;
        $secuencialTrasGuardar = (int) $this->company->fresh()->secuencial;
        $this->assertSame('001-001-000000001', $numeroEnPantalla);

        $resp = $this->sinAvisos(fn () => $this->postJson("/api/guias-remision/{$guia->id}/emit", $cuerpo)->assertOk());

        $doc = SriDocument::where('tipo_comprobante', 'guiaRemision')->where('id', '!=', $guia->id)->firstOrFail();
        $xml = $this->xmlDe($doc);
        $this->assertSame($doc->clave_acceso, $resp->json('sri_document.clave_acceso'));
        $this->assertSame('guiaRemision', $xml->getName());
        $this->assertClave($xml, '06', $doc, $this->secuencialDe($numeroEnPantalla));
        $this->assertSame(now()->format('dmY'), substr((string) $xml->infoTributaria->claveAcceso, 0, 8), 'La clave lleva la fecha de emisión de hoy');
        $this->assertSame($secuencialTrasGuardar, (int) $this->company->fresh()->secuencial, 'Emitir no gasta otro número: ya se reservó al guardar');

        $info = $xml->infoGuiaRemision;
        $this->assertSame('SI', (string) $info->obligadoContabilidad);
        $this->assertSame('Av. Amazonas N24-15, Quito', (string) $info->dirPartida);
        $this->assertSame('1790012345001', (string) $info->rucTransportista);
        $this->assertSame('PBA-1234', (string) $info->placa);
        $this->assertSame(now()->format('d/m/Y'), (string) $info->fechaIniTransporte);
        $this->assertSame(now()->addDay()->format('d/m/Y'), (string) $info->fechaFinTransporte);
        $dest = $xml->destinatarios->destinatario;
        $this->assertSame(['1700000001', 'Venta de mercadería'], [(string) $dest->identificacionDestinatario, (string) $dest->motivoTraslado]);
        $this->assertSame(['SERV-1', 'Caja de repuestos', '3.00'], [(string) $dest->detalles->detalle->codigoInterno, (string) $dest->detalles->detalle->descripcion, (string) $dest->detalles->detalle->cantidad]);
        $this->assertSinCamposVacios($xml);

        // Una guía ya emitida no se emite otra vez: saldría un segundo comprobante con otra clave
        $this->postJson("/api/guias-remision/{$guia->id}/emit", $cuerpo)->assertStatus(422);
        $this->assertSame(1, SriDocument::where('tipo_comprobante', 'guiaRemision')->where('id', '!=', $guia->id)->count());
    }

    public function test_una_liquidacion_ya_emitida_no_se_emite_dos_veces(): void
    {
        $id = $this->postJson('/api/liquidacion-compra', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'forma_pago' => '01',
            'items' => [['codigo_principal' => 'LIQ-001', 'descripcion' => 'Artesanías', 'cantidad' => 1, 'precio_unitario' => 50, 'base_imponible' => 50, 'tarifa' => 0]],
            'total_sin_impuestos' => 50, 'total_impuesto' => 0, 'importe_total' => 50,
        ])->assertCreated()->json('purchase.id');

        $this->sinAvisos(fn () => $this->postJson("/api/liquidacion-compra/{$id}/emit")->assertOk());
        $this->postJson("/api/liquidacion-compra/{$id}/emit")->assertStatus(422);

        $this->assertSame(1, SriDocument::where('tipo_comprobante', 'liquidacionCompra')->count());
    }

    // ------------------------------------------------------------ chequeo contable de lo que se emitió

    public function test_el_chequeo_estricto_sigue_en_verde_despues_de_emitir_todo(): void
    {
        $factura = $this->facturar();
        $nd = $this->nuevaNotaDebito($factura);
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'sri',
            'motivo' => 'Devolución', 'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 20, 'tarifa' => 15]],
        ])->assertCreated();
        $nc = CreditNote::latest('id')->firstOrFail();

        $this->sinAvisos(fn () => $this->postJson("/api/notas-debito/{$nd->id}/emit")->assertOk());
        $this->sinAvisos(fn () => $this->postJson("/api/credit-notes/{$nc->id}/emit")->assertOk());

        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
        // Factura, nota de débito y nota de crédito: cada una con su comprobante y sin números repetidos
        $this->assertSame(3, SriDocument::count());
        $claves = SriDocument::pluck('clave_acceso');
        $this->assertSame($claves->count(), $claves->unique()->count());
    }
}
