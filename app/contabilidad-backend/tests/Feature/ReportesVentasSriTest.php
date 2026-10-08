<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SriDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.6b: reportes de ventas.
 *  (1) El reporte de comprobantes con el filtro "Nota de crédito" miraba la tabla de facturas (siempre vacía): ahora lista las
 *      notas de crédito reales (número, fecha, cliente, factura afectada, total, SRI/interna, estado), también en Excel/PDF.
 *  (2) Ventas por tarifa: por LÍNEA, con su tarifa real: 15%, otras tarifas (5%, 12%…), 0%, No objeto de IVA y Exento.
 *  (3) Ventas, Ventas detallada y el panel contaban solo facturas AUTORIZADAS: una empresa sin certificado (todo "generado") veía
 *      ceros. Ahora cuentan toda factura vigente (generada, firmada, enviada, autorizada o sin comprobante) y excluyen las
 *      anuladas y las que el SRI rechazó; `solo_autorizadas=1` vuelve al criterio estricto. Siempre con columna "Estado SRI".
 */
class ReportesVentasSriTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Empresa SIN certificado de firma: todo lo que emite se queda "generado"
        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Reportes SRI Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * Una factura con sus líneas y, si se pide, su comprobante del SRI en ese estado. Los totales salen de las líneas
     * (cantidad × precio − descuento; IVA = base × tarifa, salvo las de código 6 No objeto y 7 Exento).
     */
    private function factura(array $lineas, ?string $sri = 'generado', array $extra = []): Invoice
    {
        $this->n++;
        $base = 0.0;
        $iva = 0.0;
        $items = [];
        foreach ($lineas as $l) {
            $sub = round($l['cantidad'] * $l['precio_unitario'] - ($l['descuento'] ?? 0), 2);
            $tarifa = array_key_exists('tarifa', $l) ? (float) $l['tarifa'] : 15.0;
            $base += $sub;
            $iva += in_array($l['codigo_porcentaje'] ?? null, ['6', '7'], true) ? 0 : round($sub * $tarifa / 100, 2);
            $items[] = $l + ['codigo_principal' => 'P'.$this->n, 'descripcion' => 'Producto '.$this->n];
        }
        $f = Invoice::create($extra + [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => sprintf('001-001-%09d', $this->n), 'items' => $items,
            'total_sin_impuestos' => $base, 'total_impuesto' => $iva, 'importe_total' => round($base + $iva, 2), 'forma_pago' => 'credito',
            'saldo_pendiente' => round($base + $iva, 2), 'estado' => 'emitida', 'fecha_emision' => now(),
        ]);
        if ($sri !== null) {
            $this->sri($f, $sri);
        }

        return $f;
    }

    private function sri($documento, string $estado): SriDocument
    {
        return SriDocument::create([
            'company_id' => $this->company->id, 'documentable_type' => $documento->getMorphClass(), 'documentable_id' => $documento->getKey(),
            'tipo_comprobante' => 'factura', 'clave_acceso' => 'CLAVE-'.$documento->getMorphClass().'-'.$documento->getKey(),
            'xml' => '<x/>', 'estado' => $estado, 'ambiente' => 1, 'fecha_emision' => now(),
        ]);
    }

    private function linea(float $precio, float $tarifa = 15, array $extra = []): array
    {
        return $extra + ['cantidad' => 1, 'precio_unitario' => $precio, 'tarifa' => $tarifa];
    }

    private function ventas(array $query = [], string $ruta = 'ventas')
    {
        return $this->getJson('/api/reportes/'.$ruta.'?'.http_build_query(['company_id' => $this->company->id] + $query));
    }

    // ------------------------------------------------------------ (3) sin certificado: nada se queda en cero

    public function test_una_empresa_sin_certificado_ve_sus_ventas_aunque_nada_este_autorizado(): void
    {
        $this->factura([$this->linea(100)], 'generado');     // 115
        $this->factura([$this->linea(200)], 'firmado');      // 230
        $this->factura([$this->linea(50)], 'enviado');       // 57.50
        $this->factura([$this->linea(10)], 'AUTORIZADO');    // 11.50
        $this->factura([$this->linea(20)], null);            // sin comprobante del SRI (venta interna): 23

        $r = $this->ventas()->assertOk();

        $this->assertCount(5, $r->json('items'), 'Antes solo salía la autorizada (o nada, sin certificado)');
        $this->assertEquals(437.0, $r->json('totales.total'));
        $this->assertEquals(380.0, $r->json('totales.subtotal_15'));
        $this->assertEquals(57.0, $r->json('totales.iva'));

        $estados = collect($r->json('items'))->pluck('estado_sri', 'numero')->all();
        $this->assertSame([
            '001-001-000000001' => 'generado', '001-001-000000002' => 'firmado', '001-001-000000003' => 'enviado',
            '001-001-000000004' => 'autorizado', '001-001-000000005' => 'sin sri',
        ], $estados);
    }

    public function test_las_anuladas_las_rechazadas_y_las_notas_de_debito_no_cuentan_como_venta(): void
    {
        $this->factura([$this->linea(100)], 'generado');                                  // vale
        $this->factura([$this->linea(900)], 'generado', ['estado' => 'anulado']);         // anulada
        $this->factura([$this->linea(800)], 'NO AUTORIZADO');                             // el SRI la rechazó
        $this->factura([$this->linea(700)], 'DEVUELTA');                                  // el SRI la devolvió
        $this->factura([$this->linea(600)], null, ['tipo_comprobante' => 'nota_debito']); // una nota de débito no es venta
        $this->factura([$this->linea(500)], null, ['tipo_comprobante' => 'nota_debito_interna']);

        foreach (['ventas', 'ventas-detalle'] as $ruta) {
            $r = $this->ventas([], $ruta)->assertOk();
            $this->assertCount(1, $r->json('items'), $ruta);
            $this->assertSame('001-001-000000001', $r->json('items.0.numero'), $ruta);
        }
        $this->assertEquals(115.0, $this->ventas()->json('totales.total'));
    }

    public function test_solo_autorizadas_vuelve_al_criterio_estricto(): void
    {
        $this->factura([$this->linea(100)], 'generado');
        $autorizada = $this->factura([$this->linea(10)], 'autorizado');   // el estado puede venir en minúsculas
        $this->factura([$this->linea(20)], null);
        $mayus = $this->factura([$this->linea(30)], 'AUTORIZADO');

        foreach (['ventas', 'ventas-detalle'] as $ruta) {
            $r = $this->ventas(['solo_autorizadas' => 1], $ruta)->assertOk();
            $this->assertEqualsCanonicalizing([$autorizada->numero, $mayus->numero], array_values(array_unique(array_column($r->json('items'), 'numero'))), $ruta);
        }
        $this->assertEquals(46.0, $this->ventas(['solo_autorizadas' => 'true'])->json('totales.total'));
        $this->assertCount(4, $this->ventas(['solo_autorizadas' => 0])->json('items'), 'En falso cuenta todo lo vigente');
    }

    public function test_el_reporte_respeta_el_rango_de_fechas_y_la_empresa(): void
    {
        $this->factura([$this->linea(100)], 'generado', ['fecha_emision' => '2026-09-10']);
        $this->factura([$this->linea(200)], 'generado', ['fecha_emision' => '2026-10-02']);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $this->factura([$this->linea(300)], 'generado', ['company_id' => $otra->id, 'contact_id' => Contact::create([
            'company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000009', 'razon_social' => 'Ajeno'])->id]);

        $r = $this->ventas(['desde' => '2026-10-01', 'hasta' => '2026-10-31'])->assertOk();

        $this->assertCount(1, $r->json('items'));
        $this->assertEquals(230.0, $r->json('totales.total'));
    }

    // ------------------------------------------------------------ (2) ventas por tarifa, por línea

    public function test_las_ventas_se_separan_por_linea_en_15_otras_tarifas_0_no_objeto_y_exento(): void
    {
        $this->factura([
            $this->linea(100, 15),                                          // 15%: base 100, IVA 15
            $this->linea(50, 5),                                            // 5%: base 50, IVA 2.50
            $this->linea(40, 0),                                            // 0%
            $this->linea(30, 0, ['codigo_porcentaje' => '6']),              // No objeto de IVA
            $this->linea(20, 0, ['codigo_porcentaje' => '7']),              // Exento de IVA
            ['cantidad' => 2, 'precio_unitario' => 60, 'descuento' => 20],  // sin tarifa: cuenta 15% como en la factura; 120 - 20 = 100
        ], 'generado');

        $r = $this->ventas()->assertOk();
        $fila = $r->json('items.0');

        $this->assertEquals(200.0, $fila['subtotal_15'], 'Dos líneas al 15% (100 + 100 con descuento)');
        $this->assertEquals(50.0, $fila['subtotal_otras'], 'Las otras tarifas positivas, aparte del 15%');
        $this->assertEquals(40.0, $fila['subtotal_0']);
        $this->assertEquals(30.0, $fila['no_objeto']);
        $this->assertEquals(20.0, $fila['exento']);
        $this->assertEquals(32.5, $fila['iva']);
        $this->assertEquals(372.5, $fila['total']);
        // Las bases suman el total sin impuestos de la factura
        $this->assertEquals(340.0, $fila['subtotal_15'] + $fila['subtotal_otras'] + $fila['subtotal_0'] + $fila['no_objeto'] + $fila['exento']);
        $this->assertEquals(340.0, Invoice::firstOrFail()->total_sin_impuestos);

        $this->assertEquals(200.0, $r->json('totales.subtotal_15'));
        $this->assertEquals(50.0, $r->json('totales.subtotal_otras'));
        $this->assertEquals(30.0, $r->json('totales.no_objeto'));
        $this->assertEquals(20.0, $r->json('totales.exento'));

        // El resumen por tarifa trae solo las que existen, con base e IVA
        $porTarifa = collect($r->json('por_tarifa'))->keyBy('codigo');
        $this->assertSame(['15', '5', '0', 'no_objeto', 'exento'], collect($r->json('por_tarifa'))->pluck('codigo')->all());
        $this->assertSame('IVA 15%', $porTarifa['15']['etiqueta']);
        $this->assertEquals(200.0, $porTarifa['15']['base']);
        $this->assertEquals(30.0, $porTarifa['15']['iva']);
        $this->assertEquals(2.5, $porTarifa['5']['iva']);
        $this->assertSame('No objeto de IVA', $porTarifa['no_objeto']['etiqueta']);
        $this->assertSame('Exento de IVA', $porTarifa['exento']['etiqueta']);
        $this->assertEquals(0.0, $porTarifa['0']['iva']);
    }

    public function test_cada_otra_tarifa_sale_en_el_resumen_con_su_nombre(): void
    {
        $this->factura([$this->linea(100, 12), $this->linea(100, 13), $this->linea(100, 15)], 'generado');

        $resumen = $this->ventas()->json('por_tarifa');
        $porTarifa = collect($resumen)->keyBy('codigo');

        $this->assertSame(['15', '13', '12'], array_column($resumen, 'codigo'), 'De la mayor tarifa a la menor');
        $this->assertSame('IVA 12%', $porTarifa['12']['etiqueta']);
        $this->assertEquals(12.0, $porTarifa['12']['iva']);
        $this->assertEquals(200.0, $this->ventas()->json('totales.subtotal_otras'));
    }

    public function test_una_factura_sin_lineas_guardadas_usa_su_total_sin_impuestos(): void
    {
        // Facturas viejas sin detalle: la base va al 15% si cobró IVA y al 0% si no
        $this->factura([$this->linea(100)], 'generado');
        $vieja = $this->factura([$this->linea(10)], 'generado');
        $vieja->update(['items' => [], 'total_sin_impuestos' => 80, 'total_impuesto' => 12, 'importe_total' => 92]);
        $viejaCero = $this->factura([$this->linea(10)], 'generado');
        $viejaCero->update(['items' => [], 'total_sin_impuestos' => 60, 'total_impuesto' => 0, 'importe_total' => 60]);

        $totales = $this->ventas()->json('totales');

        $this->assertEquals(180.0, $totales['subtotal_15']);
        $this->assertEquals(60.0, $totales['subtotal_0']);
    }

    public function test_ventas_detallada_trae_estado_sri_categoria_y_la_base_neta_de_descuento(): void
    {
        $this->factura([
            $this->linea(100, 15, ['cantidad' => 2, 'descuento' => 20]),
            $this->linea(40, 0, ['codigo_porcentaje' => '6']),
            ['cantidad' => 1, 'precio_unitario' => 10],
        ], 'firmado');

        $items = $this->ventas([], 'ventas-detalle')->assertOk()->json('items');

        $this->assertCount(3, $items);
        $this->assertSame('firmado', $items[0]['estado_sri']);
        $this->assertEquals(180.0, $items[0]['subtotal'], '2 × 100 − 20 de descuento');
        $this->assertEquals(20.0, $items[0]['descuento']);
        $this->assertEquals(15.0, $items[0]['tarifa']);
        $this->assertSame('15%', $items[0]['categoria']);
        $this->assertSame('No objeto de IVA', $items[1]['categoria']);
        $this->assertSame('15%', $items[2]['categoria'], 'Sin tarifa cuenta 15% como en la factura');
    }

    // ------------------------------------------------------------ Excel y PDF

    public function test_el_excel_de_ventas_trae_la_columna_estado_sri_y_las_nuevas_bases(): void
    {
        $this->factura([$this->linea(100), $this->linea(40, 0, ['codigo_porcentaje' => '7'])], 'generado');
        $this->factura([$this->linea(10)], 'AUTORIZADO');

        $csv = $this->ventas(['formato' => 'excel'])->assertOk()->getContent();
        $lineas = array_values(array_filter(explode("\n", $csv)));

        $this->assertStringContainsString('"Estado SRI"', $lineas[0]);
        $this->assertStringContainsString('"No objeto IVA"', $lineas[0]);
        $this->assertStringContainsString('"Exento"', $lineas[0]);
        $filas = collect($lineas)->slice(1)->values();
        $this->assertCount(2, $filas);
        $this->assertTrue($filas->contains(fn ($l) => str_contains($l, '"001-001-000000001"') && str_contains($l, '"Generado"')));
        $this->assertTrue($filas->contains(fn ($l) => str_contains($l, '"001-001-000000002"') && str_contains($l, '"Autorizado"')));
    }

    public function test_el_pdf_de_ventas_se_genera_con_estado_sri_y_filtro(): void
    {
        $this->factura([$this->linea(100)], 'generado');

        $r = $this->ventas(['formato' => 'pdf', 'solo_autorizadas' => 1])->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));

        $r = $this->ventas(['formato' => 'pdf'], 'ventas-detalle')->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------ panel

    public function test_el_panel_cuenta_las_ventas_sin_certificado_pero_no_las_rechazadas_ni_las_anuladas(): void
    {
        $this->factura([$this->linea(100)], 'generado');                                // 115
        $this->factura([$this->linea(200)], 'firmado');                                 // 230
        $this->factura([$this->linea(400)], 'NO AUTORIZADO');                           // rechazada: no cuenta
        $this->factura([$this->linea(800)], 'generado', ['estado' => 'anulado']);       // anulada: no cuenta
        $this->factura([$this->linea(50)], null, ['tipo_comprobante' => 'nota_debito']);

        $r = $this->getJson('/api/dashboard/resumen?company_id='.$this->company->id)->assertOk();

        $this->assertEquals(345.0, $r->json('ventas_mes'), 'Sin certificado el panel no puede mostrar cero');
        $serie = collect($r->json('ventas_serie'))->last();
        $this->assertEquals(345.0, $serie['total']);

        // "Documentos SRI por autorizar": generado + firmado + el rechazado; el comprobante de la factura anulada no cuenta
        // (igual que la lista de Documentos SRI, que no lo ofrece para enviar)
        $pendientes = collect($r->json('acciones'))->firstWhere('key', 'sri');
        $this->assertSame(3, $pendientes['cantidad']);
        $estados = collect($r->json('documentos'))->pluck('estado_sri.chip', 'numero');
        $this->assertSame('crit', $estados['001-001-000000003'], 'Lo que el SRI rechazó se marca en rojo en el panel');
    }

    // ------------------------------------------------------------ (1) reporte de comprobantes con notas de crédito

    private function notaCredito(Invoice $factura, string $tipo, float $total, ?string $numero, string $fecha = '2026-10-05'): CreditNote
    {
        return CreditNote::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => $tipo,
            'numero' => $numero, 'fecha' => $fecha, 'motivo' => 'Ajuste', 'total_sin_impuestos' => $total, 'total_impuesto' => 0,
            'importe_total' => $total, 'saldo_disponible' => 0,
        ]);
    }

    private function comprobantes(array $query = [])
    {
        return $this->getJson('/api/reportes/comprobantes?'.http_build_query(['company_id' => $this->company->id] + $query));
    }

    public function test_el_filtro_nota_de_credito_lista_las_notas_de_credito_reales(): void
    {
        $factura = $this->factura([$this->linea(100)], 'AUTORIZADO');
        $sri = $this->notaCredito($factura, 'sri', 11.5, '001-001-000000050');
        $this->sri($sri, 'AUTORIZADO');
        $this->notaCredito($factura, 'interna', 5, 'NCI-000001', '2026-10-06');
        $this->notaCredito($factura, 'sri', 3, null, '2026-10-07');                    // SRI sin emitir todavía
        $this->notaCredito($factura, 'anulado', 2, 'NCI-000002', '2026-10-08');        // interna anulada (el tipo ya no lo dice)

        $items = collect($this->comprobantes(['tipo' => 'nota_credito'])->assertOk()->json('items'));

        $this->assertCount(4, $items, 'Antes salía vacío: miraba la tabla de facturas');
        $this->assertSame(['Nota de crédito'], $items->pluck('tipo')->unique()->values()->all());
        $porNumero = $items->keyBy(fn ($x) => $x['numero']);

        $autorizada = $porNumero['001-001-000000050'];
        $this->assertSame('Cliente Normal', $autorizada['cliente']);
        $this->assertSame('2026-10-05', $autorizada['fecha_emision']);
        $this->assertSame($factura->numero, $autorizada['factura_afectada']);
        $this->assertSame('SRI', $autorizada['origen']);
        $this->assertSame('autorizado', $autorizada['estado']);
        $this->assertEquals(11.5, $autorizada['total']);

        $this->assertSame('Interna', $porNumero['NCI-000001']['origen']);
        $this->assertSame('emitida', $porNumero['NCI-000001']['estado']);
        $this->assertSame('pendiente', $items->firstWhere('total', 3.0)['estado'], 'La SRI que aún no se emite');
        $this->assertSame('anulado', $porNumero['NCI-000002']['estado']);
        $this->assertSame('Interna', $porNumero['NCI-000002']['origen'], 'Una nota interna anulada sigue siendo interna');
        // Las más recientes primero
        $this->assertSame(['2026-10-08', '2026-10-07', '2026-10-06', '2026-10-05'], $items->pluck('fecha_emision')->all());
    }

    public function test_notas_de_credito_respeta_el_rango_de_fechas_y_no_trae_las_de_otra_empresa(): void
    {
        $factura = $this->factura([$this->linea(100)], 'generado');
        $this->notaCredito($factura, 'interna', 5, 'NCI-000001', '2026-09-30');
        $this->notaCredito($factura, 'interna', 6, 'NCI-000002', '2026-10-10');
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000009', 'razon_social' => 'Ajeno']);
        CreditNote::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'tipo' => 'interna', 'numero' => 'NCI-000001', 'fecha' => '2026-10-10',
            'motivo' => 'x', 'importe_total' => 99, 'saldo_disponible' => 0]);

        $items = $this->comprobantes(['tipo' => 'nota_credito', 'desde' => '2026-10-01', 'hasta' => '2026-10-31'])->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('NCI-000002', $items[0]['numero']);
    }

    public function test_sin_filtro_el_reporte_trae_facturas_notas_de_credito_y_notas_de_debito(): void
    {
        $factura = $this->factura([$this->linea(100)], 'generado');
        $this->notaCredito($factura, 'interna', 5, 'NCI-000001');
        $this->factura([$this->linea(10, 0)], 'AUTORIZADO', ['tipo_comprobante' => 'nota_debito', 'factura_referencia_id' => $factura->id, 'numero_referencia' => $factura->numero]);
        $this->factura([$this->linea(20, 0)], null, ['tipo_comprobante' => 'nota_debito_interna', 'factura_referencia_id' => $factura->id, 'numero_referencia' => $factura->numero]);

        $items = collect($this->comprobantes()->assertOk()->json('items'));

        $this->assertEqualsCanonicalizing(
            ['Factura', 'Nota de crédito', 'Nota de débito', 'Nota de débito'],
            $items->pluck('tipo')->all(),
        );

        $debitos = collect($this->comprobantes(['tipo' => 'nota_debito'])->json('items'));
        $this->assertCount(2, $debitos, 'La nota de débito interna también aparece');
        $this->assertEqualsCanonicalizing(['SRI', 'Interna'], $debitos->pluck('origen')->all());
        $this->assertSame([$factura->numero], $debitos->pluck('factura_afectada')->unique()->values()->all());

        $facturas = collect($this->comprobantes(['tipo' => 'factura'])->json('items'));
        $this->assertCount(1, $facturas);
        $this->assertSame('generado', $facturas[0]['estado']);
    }

    public function test_el_excel_de_comprobantes_trae_las_notas_de_credito_con_su_factura_y_origen(): void
    {
        $factura = $this->factura([$this->linea(100)], 'generado');
        $this->notaCredito($factura, 'interna', 5, 'NCI-000001');

        $csv = $this->comprobantes(['tipo' => 'nota_credito', 'formato' => 'excel'])->assertOk()->getContent();
        $lineas = array_values(array_filter(explode("\n", $csv)));

        $this->assertStringContainsString('"Factura afectada"', $lineas[0]);
        $this->assertStringContainsString('"SRI/Interna"', $lineas[0]);
        $this->assertCount(2, $lineas);
        $this->assertStringContainsString('"NCI-000001"', $lineas[1]);
        $this->assertStringContainsString('"'.$factura->numero.'"', $lineas[1]);
        $this->assertStringContainsString('"Interna"', $lineas[1]);
        $this->assertStringContainsString('"5.00"', $lineas[1]);

        $this->comprobantes(['tipo' => 'nota_credito', 'formato' => 'pdf'])->assertOk();
    }
}
