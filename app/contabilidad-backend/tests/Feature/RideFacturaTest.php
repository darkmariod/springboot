<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RideBuilder;
use App\Support\Code128;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.3: el RIDE (la factura impresa) lleva los datos del emisor y los de la autorización del SRI.
 *   - Un documento AUTORIZADO imprime el número y la fecha y hora que devolvió el SRI.
 *   - Uno que no lo está (sin certificado, generado, firmado, enviado o rechazado) imprime una leyenda clara y NUNCA
 *     inventa un número ni una fecha de autorización.
 * El PDF sale del servidor (dompdf) desde GET /api/invoices/{id}/ride.
 */
class RideFacturaTest extends TestCase
{
    use RefreshDatabase;

    private const AUTORIZACION = '0710202601179123456700110010010000000011234567810';

    private Company $company;
    private Contact $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // dompdf carga las tablas de las fuentes: con la suite completa en un solo proceso, el límite de 128 MB de php.ini
        // se agotaba aquí ("Allowed memory size exhausted" en php-font-lib) en cuanto la suite pasó de ~390 pruebas.
        $limite = trim((string) ini_get('memory_limit'));
        $bytes = $limite === '-1' ? PHP_INT_MAX : (int) $limite * match (strtoupper(substr($limite, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
        if ($bytes < 512 * 1048576) {
            ini_set('memory_limit', '512M');
        }

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Distribuidora Andina SA', 'nombre_comercial' => 'Andina Express',
            'dir_matriz' => 'Av. Amazonas N24-15 y Colón, Quito', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
            'ambiente' => 1, 'obligado_contabilidad' => true, 'contribuyente_especial' => '5368', 'regimen' => '2',
            'telefonos' => '022555111', 'email_envio' => 'facturas@andina.test',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001',
            'razon_social' => 'María Fernanda Pazmiño', 'direccion' => 'Calle Los Pinos 45', 'email' => 'maria@cliente.test', 'telefono' => '0999999999',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    /** Factura por la ruta normal: queda con su comprobante "generado" (sin certificado nada sale al SRI). */
    private function facturar(?array $items = null, string $forma = 'efectivo'): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => $forma,
            'items' => $items ?? [
                ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 2, 'precio_unitario' => 50, 'tarifa' => 15, 'descuento' => 10],
                ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio exento', 'cantidad' => 1, 'precio_unitario' => 20, 'tarifa' => 0],
            ],
        ])->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    private function autorizar(Invoice $f, ?string $fechaSri = '2026-10-07T10:15:30-05:00'): SriDocument
    {
        $doc = $f->sriDocument()->firstOrFail();
        $doc->update([
            'estado' => 'AUTORIZADO', 'numero_autorizacion' => self::AUTORIZACION,
            'mensajes' => $fechaSri === null ? ['importado' => 'xml_ventas_emitidas']
                : ['RespuestaAutorizacionComprobante' => ['autorizaciones' => ['autorizacion' => ['estado' => 'AUTORIZADO', 'numeroAutorizacion' => self::AUTORIZACION, 'fechaAutorizacion' => $fechaSri]]]],
        ]);

        return $doc;
    }

    private function ride(Invoice $f): array
    {
        return app(RideBuilder::class)->paraFactura($f->fresh());
    }

    // ------------------------------------------------------------ Code 128 (el código de barras de la clave de acceso)

    public function test_code128_calcula_los_simbolos_y_el_digito_verificador_conocidos(): void
    {
        // Juego C: inicio 105, pares 12 34 56 78, verificador (105 + 12*1 + 34*2 + 56*3 + 78*4) % 103 = 47, parada 106
        $this->assertSame([105, 12, 34, 56, 78, 47, 106], Code128::simbolos('12345678'));
        // Cantidad impar de dígitos: el primero va en el juego B ('1' = 17), luego se pasa al C (99)
        $this->assertSame([104, 17, 99, 23, 45, (104 + 17 * 1 + 99 * 2 + 23 * 3 + 45 * 4) % 103, 106], Code128::simbolos('12345'));
    }

    public function test_code128_cada_simbolo_mide_11_modulos_y_la_parada_13(): void
    {
        $patrones = Code128::patrones();
        $this->assertCount(107, $patrones);
        foreach (array_slice($patrones, 0, 106) as $valor => $p) {
            $this->assertSame(6, strlen($p), "símbolo $valor");
            $this->assertSame(11, array_sum(str_split($p)), "El símbolo $valor debe medir 11 módulos");
        }
        $this->assertSame(13, array_sum(str_split($patrones[106])));
    }

    public function test_code128_de_una_clave_de_acceso_de_49_digitos_es_estable_y_cabe_en_la_pagina(): void
    {
        $clave = self::AUTORIZACION;
        $this->assertSame(49, strlen($clave));
        $simbolos = Code128::simbolos($clave);
        // Inicio B + dígito + cambio a C + 24 pares + verificador + parada
        $this->assertCount(1 + 1 + 1 + 24 + 1 + 1, $simbolos);
        $this->assertLessThan(103, $simbolos[count($simbolos) - 2], 'El verificador es un símbolo válido');
        $this->assertSame(Code128::svg($clave, 44, 2), Code128::svg($clave, 44, 2), 'El mismo texto da siempre los mismos bytes');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', Code128::dataUri($clave));
        $this->assertLessThan(700, Code128::modulos($clave) * 2 + 40, 'Cabe en el ancho útil de la hoja');
        $this->expectException(\InvalidArgumentException::class);
        Code128::simbolos('');
    }

    // ------------------------------------------------------------ los datos del RIDE (RideBuilder)

    public function test_el_ride_trae_los_datos_del_emisor(): void
    {
        $d = $this->ride($this->facturar());

        $e = $d['emisor'];
        $this->assertSame('Distribuidora Andina SA', $e['razon_social']);
        $this->assertSame('Andina Express', $e['nombre_comercial']);
        $this->assertSame('1791234567001', $e['ruc']);
        $this->assertSame('Av. Amazonas N24-15 y Colón, Quito', $e['dir_matriz']);
        $this->assertSame('Av. Amazonas N24-15 y Colón, Quito', $e['dir_sucursal'], 'Sin sucursal el establecimiento es la matriz');
        $this->assertSame('SI', $e['obligado_contabilidad']);
        $this->assertSame('5368', $e['contribuyente_especial']);
        $this->assertSame('CONTRIBUYENTE RÉGIMEN RIMPE', $e['regimen']);
        $this->assertSame('022555111', $e['telefonos']);
        $this->assertSame('PRUEBAS', $d['ambiente']);
        $this->assertSame('NORMAL', $d['emision']);
        $this->assertSame('FACTURA', $d['tipo']);
    }

    public function test_el_ride_de_una_factura_de_sucursal_usa_la_direccion_del_establecimiento(): void
    {
        $sucursal = Branch::create(['company_id' => $this->company->id, 'estab' => '002', 'nombre' => 'Sucursal Norte', 'direccion' => 'Av. Eloy Alfaro 500, Quito', 'es_matriz' => false, 'activa' => true]);
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'efectivo',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();
        $f = Invoice::latest('id')->firstOrFail();
        $f->update(['branch_id' => $sucursal->id]);

        $d = $this->ride($f);
        $this->assertSame('Av. Eloy Alfaro 500, Quito', $d['emisor']['dir_sucursal']);
        $this->assertSame('Av. Amazonas N24-15 y Colón, Quito', $d['emisor']['dir_matriz']);
    }

    public function test_un_documento_autorizado_imprime_el_numero_y_la_fecha_que_devolvio_el_sri(): void
    {
        $f = $this->facturar();
        $this->autorizar($f);

        $d = $this->ride($f);

        $this->assertTrue($d['autorizacion']['autorizado']);
        $this->assertSame(self::AUTORIZACION, $d['autorizacion']['numero']);
        $this->assertSame('07/10/2026 10:15:30', $d['autorizacion']['fecha_hora'], 'La fecha y hora tal como las devolvió el SRI');
        $this->assertNull($d['leyenda'], 'Un documento autorizado no lleva la leyenda de pendiente');
        $this->assertSame(49, strlen($d['clave_acceso']));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $d['codigo_barras']);
        $this->assertSame($f->numero, $d['numero']);
    }

    public function test_un_documento_autorizado_importado_sin_fecha_en_la_respuesta_no_inventa_una(): void
    {
        $f = $this->facturar();
        $this->autorizar($f, null);

        $a = $this->ride($f)['autorizacion'];

        $this->assertSame(self::AUTORIZACION, $a['numero']);
        $this->assertNull($a['fecha_hora'], 'Sin fecha del SRI no se imprime ninguna');
    }

    public function test_un_documento_no_autorizado_lleva_la_leyenda_y_ningun_numero_ni_fecha_de_autorizacion(): void
    {
        $f = $this->facturar();   // sin certificado: queda "generado"
        $this->assertSame('generado', $f->sriDocument->estado);

        $d = $this->ride($f);

        $this->assertFalse($d['autorizacion']['autorizado']);
        $this->assertNull($d['autorizacion']['numero']);
        $this->assertNull($d['autorizacion']['fecha_hora']);
        $this->assertSame('pendiente', $d['autorizacion']['estado']);
        $this->assertSame('Documento pendiente de autorización del SRI — sin validez tributaria hasta ser autorizado', $d['leyenda']);
        $this->assertSame(49, strlen($d['clave_acceso']), 'La clave de acceso sí existe desde que se genera el comprobante');
    }

    public function test_cada_estado_sin_autorizar_da_su_leyenda_y_nunca_un_numero(): void
    {
        $f = $this->facturar();
        foreach (['generado', 'firmado', 'enviado'] as $estado) {
            $f->sriDocument()->update(['estado' => $estado, 'numero_autorizacion' => null]);
            $d = $this->ride($f);
            $this->assertSame(RideBuilder::LEYENDA_PENDIENTE, $d['leyenda'], $estado);
            $this->assertNull($d['autorizacion']['numero'], $estado);
        }

        // Aunque el comprobante tenga un número guardado, mientras no esté AUTORIZADO no se imprime
        $f->sriDocument()->update(['estado' => 'enviado', 'numero_autorizacion' => self::AUTORIZACION]);
        $this->assertNull($this->ride($f)['autorizacion']['numero']);

        $f->sriDocument()->update(['estado' => 'NO AUTORIZADO', 'numero_autorizacion' => null]);
        $d = $this->ride($f);
        $this->assertSame(RideBuilder::LEYENDA_RECHAZADO, $d['leyenda']);
        $this->assertSame('rechazado', $d['autorizacion']['estado']);
        $this->assertNull($d['autorizacion']['numero']);
    }

    public function test_una_factura_sin_comprobante_dice_que_no_tiene_validez_tributaria(): void
    {
        $f = $this->facturar();
        $f->sriDocument()->delete();

        $d = $this->ride($f);

        $this->assertSame(RideBuilder::LEYENDA_SIN_COMPROBANTE, $d['leyenda']);
        $this->assertNull($d['clave_acceso']);
        $this->assertNull($d['codigo_barras']);
        $this->assertNull($d['autorizacion']['numero']);
    }

    public function test_los_totales_van_por_tarifa_con_el_descuento_y_el_iva(): void
    {
        // 2 x 50 - 10 = 90 al 15% (IVA 13.50) + 1 x 20 al 0%: sin impuestos 110, total 123.50
        $d = $this->ride($this->facturar());

        $t = collect($d['totales'])->pluck('valor', 'etiqueta');
        $this->assertSame('90.00', $t['SUBTOTAL 15%']);
        $this->assertSame('20.00', $t['SUBTOTAL 0%']);
        $this->assertSame('0.00', $t['SUBTOTAL NO OBJETO DE IVA']);
        $this->assertSame('0.00', $t['SUBTOTAL EXENTO DE IVA']);
        $this->assertSame('110.00', $t['SUBTOTAL SIN IMPUESTOS']);
        $this->assertSame('10.00', $t['TOTAL DESCUENTO']);
        $this->assertSame('13.50', $t['IVA 15%']);
        $this->assertSame('123.50', $t['VALOR TOTAL']);

        $this->assertCount(2, $d['lineas']);
        $this->assertSame(['codigo' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => '2.00', 'precio_unitario' => '50.00', 'descuento' => '10.00', 'total' => '90.00'], $d['lineas'][0]);
        $this->assertSame([['codigo' => '01', 'forma' => 'SIN UTILIZACIÓN DEL SISTEMA FINANCIERO', 'valor' => '123.50']], $d['pagos']);
        $this->assertSame('María Fernanda Pazmiño', $d['comprador']['razon_social']);
        $this->assertSame('CÉDULA', $d['comprador']['tipo_identificacion']);
    }

    public function test_no_objeto_y_exento_salen_en_su_propio_renglon(): void
    {
        $f = $this->facturar([
            ['codigo_principal' => 'SERV-1', 'descripcion' => 'No objeto', 'cantidad' => 1, 'precio_unitario' => 30, 'tarifa' => 0, 'codigo_porcentaje' => '6'],
            ['codigo_principal' => 'SERV-1', 'descripcion' => 'Exento', 'cantidad' => 1, 'precio_unitario' => 12, 'tarifa' => 0, 'codigo_porcentaje' => '7'],
        ], 'credito');

        $d = $this->ride($f);
        $t = collect($d['totales'])->pluck('valor', 'etiqueta');
        $this->assertSame('30.00', $t['SUBTOTAL NO OBJETO DE IVA']);
        $this->assertSame('12.00', $t['SUBTOTAL EXENTO DE IVA']);
        $this->assertSame('0.00', $t['SUBTOTAL 0%']);
        $this->assertSame('42.00', $t['VALOR TOTAL']);
        $this->assertSame('CRÉDITO', $d['condicion_pago']);
    }

    public function test_una_factura_anulada_que_el_sri_autorizo_avisa_del_portal(): void
    {
        $f = $this->facturar();
        $this->autorizar($f);
        $this->postJson("/api/invoices/{$f->id}/anular")->assertOk();

        $d = $this->ride($f);
        $this->assertTrue($d['anulado']);
        $this->assertStringContainsString('ANULADO', $d['aviso_anulado']);
        $this->assertStringContainsString('portal SRI', $d['aviso_anulado']);
    }

    // ------------------------------------------------------------ el PDF

    public function test_el_pdf_de_la_factura_empieza_con_pdf(): void
    {
        $f = $this->facturar();

        $r = $this->get("/api/invoices/{$f->id}/ride?company_id={$this->company->id}", ['Accept' => 'application/pdf'])->assertOk();

        $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('RIDE-', $r->headers->get('Content-Disposition'));
    }

    public function test_el_ride_de_otra_empresa_da_404(): void
    {
        $f = $this->facturar();
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'estab' => '001', 'pto_emi' => '001']);

        // Pidiendo la factura con el company_id de otra empresa: no existe para ella
        $this->get("/api/invoices/{$f->id}/ride?company_id={$otra->id}")->assertNotFound();

        // Un usuario (que no es administrador) de la otra empresa no ve la factura aunque ponga el company_id de la dueña
        Sanctum::actingAs(User::factory()->create(['company_id' => $otra->id, 'rol' => 'cajero']));
        $this->get("/api/invoices/{$f->id}/ride?company_id={$this->company->id}")->assertNotFound();

        // Y sin company_id no hay RIDE
        $this->getJson("/api/invoices/{$f->id}/ride")->assertStatus(422);
    }

    public function test_una_nota_de_debito_y_una_de_credito_tambien_salen_en_pdf(): void
    {
        $f = $this->facturar();
        $nc = CreditNote::create(['company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $f->id, 'tipo' => 'sri',
            'numero' => '001-001-000000001', 'fecha' => '2026-10-07', 'motivo' => 'Devolución parcial',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
            'total_sin_impuestos' => 10, 'total_impuesto' => 1.5, 'importe_total' => 11.5, 'saldo_disponible' => 0]);
        $nd = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => '001-001-000000002',
            'tipo_comprobante' => 'nota_debito', 'factura_referencia_id' => $f->id, 'estado' => 'emitida', 'fecha_emision' => now(),
            'items' => [['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0]],
            'total_sin_impuestos' => 5, 'total_impuesto' => 0, 'importe_total' => 5, 'saldo_pendiente' => 0, 'forma_pago' => 'credito']);

        $this->get("/api/credit-notes/{$nc->id}/ride?company_id={$this->company->id}")->assertOk()
            ->assertSee('%PDF', false);
        $this->get("/api/notas-debito/{$nd->id}/ride?company_id={$this->company->id}")->assertOk()
            ->assertSee('%PDF', false);

        $dNc = app(RideBuilder::class)->paraNotaCredito($nc->fresh());
        $this->assertSame('NOTA DE CRÉDITO', $dNc['tipo']);
        $this->assertSame($f->numero, $dNc['modificado']['numero']);
        $this->assertSame('Devolución parcial', $dNc['motivo']);
        $dNd = app(RideBuilder::class)->paraNotaDebito($nd->fresh());
        $this->assertSame('NOTA DE DÉBITO', $dNd['tipo']);
        $this->assertSame('5.00', collect($dNd['totales'])->pluck('valor', 'etiqueta')['VALOR TOTAL']);

        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'estab' => '001', 'pto_emi' => '001']);
        $this->get("/api/credit-notes/{$nc->id}/ride?company_id={$otra->id}")->assertNotFound();
        $this->get("/api/notas-debito/{$nd->id}/ride?company_id={$otra->id}")->assertNotFound();
    }

    /** Un PDF de verdad, leído con pdftotext: lo que se imprime es lo que dice el RideBuilder. */
    public function test_el_pdf_real_muestra_los_datos_del_emisor_y_la_leyenda_de_pendiente(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext no está instalado: no se puede leer el PDF.');
        }
        $f = $this->facturar();
        $clave = $f->sriDocument->clave_acceso;

        $pdf = $this->get("/api/invoices/{$f->id}/ride?company_id={$this->company->id}")->assertOk()->getContent();
        $texto = $this->textoDelPdf($pdftotext, $pdf);

        foreach (['Distribuidora Andina SA', 'Andina Express', '1791234567001', 'Av. Amazonas N24-15 y Colón, Quito', 'FACTURA',
            $f->numero, $clave, 'PRUEBAS', 'NORMAL', 'OBLIGADO A LLEVAR CONTABILIDAD', '5368',
            'María Fernanda Pazmiño', '1700000001', 'Servicio de instalación', 'SUBTOTAL 15%', 'VALOR TOTAL', '123.50',
            'SIN UTILIZACIÓN DEL SISTEMA FINANCIERO', 'maria@cliente.test'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto, "El PDF debe mostrar «{$esperado}»");
        }
        $this->assertStringContainsString('Documento pendiente de autorización del SRI', $texto);
        $this->assertStringNotContainsString(self::AUTORIZACION, $texto, 'No se imprime ningún número de autorización inventado');
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf), 'Una factura corta cabe en una página');
        $this->assertLessThan(200_000, strlen($pdf), 'Con subconjunto de fuentes el PDF pesa KB, no el MB de la fuente completa');
    }

    public function test_una_factura_larga_sigue_en_otra_pagina_sin_perder_el_total(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext no está instalado: no se puede leer el PDF.');
        }
        $items = [];
        for ($i = 1; $i <= 70; $i++) {
            $items[] = ['codigo_principal' => 'SERV-1', 'descripcion' => "Servicio mensual número {$i}", 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15];
        }
        $f = $this->facturar($items, 'credito');   // 70 x 10 = 700 + IVA 105 = 805.00

        $pdf = $this->get("/api/invoices/{$f->id}/ride?company_id={$this->company->id}")->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf), 'Setenta renglones no caben en una página');
        $texto = $this->textoDelPdf($pdftotext, $pdf);
        $this->assertStringContainsString('Servicio mensual número 1', $texto);
        $this->assertStringContainsString('Servicio mensual número 70', $texto);
        $this->assertStringContainsString('805.00', $texto);
    }

    public function test_el_pdf_real_de_un_documento_autorizado_muestra_numero_y_fecha_y_no_la_leyenda(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext no está instalado: no se puede leer el PDF.');
        }
        $f = $this->facturar();
        $this->autorizar($f);

        $texto = $this->textoDelPdf($pdftotext, $this->get("/api/invoices/{$f->id}/ride?company_id={$this->company->id}")->assertOk()->getContent());

        $this->assertStringContainsString(self::AUTORIZACION, $texto);
        $this->assertStringContainsString('07/10/2026 10:15:30', $texto);
        $this->assertStringNotContainsString('pendiente de autorización', $texto);
    }

    private function textoDelPdf(string $pdftotext, string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ride').'.pdf';
        file_put_contents($ruta, $contenido);
        try {
            // pdftotext parte las líneas largas (la clave de acceso); se juntan los espacios para comparar
            $texto = (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
        } finally {
            @unlink($ruta);
        }

        return $texto;
    }
}
