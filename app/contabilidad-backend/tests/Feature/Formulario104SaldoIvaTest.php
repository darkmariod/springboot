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
use App\Models\Withholding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F9-3: el saldo de IVA del formulario 104.
 *
 *   IVA a pagar = IVA cobrado en facturas
 *               − IVA de las notas de crédito emitidas en el período   (bajan ventas e IVA cobrado)
 *               − IVA pagado en compras                                (crédito tributario)
 *               − retenciones de IVA que nos hicieron                  (solo las de IVA)
 *               + retenciones de IVA que nosotros hicimos como agente  (se pagan con la declaración)
 *
 * Las retenciones de RENTA que nos hicieron no bajan el IVA (son crédito para el impuesto a la renta) y las de renta que nosotros
 * hicimos van al formulario 103, no al 104. Antes se restaban todas las recibidas, no se descontaban las notas de crédito y se
 * sumaban todas las emitidas.
 */
class Formulario104SaldoIvaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Distribuidora Andina SA', 'dir_matriz' => 'Av. Amazonas N24-15, Quito',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'ambiente' => 1, 'obligado_contabilidad' => true,
        ]);
        $this->cliente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Cliente SA']);
        $this->proveedor = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011224001', 'razon_social' => 'Proveedor SA', 'email' => 'prov@test.com']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));
    }

    // ------------------------------------------------------------ ayudas

    private function facturar(float $base, float $tarifa = 15): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    private function notaDeCredito(Invoice $factura, float $base, string $tipo = 'sri', float $tarifa = 15): CreditNote
    {
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => $tipo,
            'motivo' => 'Devolución parcial', 'devuelve_stock' => false,
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio devuelto', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return CreditNote::latest('id')->firstOrFail();
    }

    private function comprar(float $base, ?string $fecha = null, string $numero = '001-001-000000099'): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero,
            'fecha_emision' => $fecha ?? now()->toDateString(),
            'items' => [['codigo_principal' => 'COMP-1', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::latest('id')->firstOrFail();
    }

    /** Retención RECIBIDA (XML del cliente) con una línea de renta y otra de IVA. */
    private function retencionRecibida(float $renta, float $iva, string $clave): void
    {
        $lineas = '';
        if ($renta > 0) {
            $lineas .= '<impuesto><codigo>1</codigo><codigoRetencion>303</codigoRetencion><baseImponible>100.00</baseImponible><porcentajeRetener>10</porcentajeRetener>'
                .'<valorRetenido>'.number_format($renta, 2, '.', '').'</valorRetenido><codDocSustento>01</codDocSustento><numDocSustento>001001000000999</numDocSustento></impuesto>';
        }
        if ($iva > 0) {
            $lineas .= '<impuesto><codigo>2</codigo><codigoRetencion>2</codigoRetencion><baseImponible>150.00</baseImponible><porcentajeRetener>30</porcentajeRetener>'
                .'<valorRetenido>'.number_format($iva, 2, '.', '').'</valorRetenido><codDocSustento>01</codDocSustento><numDocSustento>001001000000999</numDocSustento></impuesto>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><comprobanteRetencion id="comprobante" version="1.0.0"><infoTributaria><ambiente>1</ambiente><tipoEmision>1</tipoEmision>'
            .'<razonSocial>Cliente que retiene</razonSocial><ruc>1790011223001</ruc><claveAcceso>'.$clave.'</claveAcceso><codDoc>07</codDoc><estab>001</estab><ptoEmi>001</ptoEmi><secuencial>000000001</secuencial></infoTributaria>'
            .'<infoCompRetencion><fechaEmision>'.now()->format('d/m/Y').'</fechaEmision><identificacionSujetoRetenido>1791234567001</identificacionSujetoRetenido></infoCompRetencion>'
            .'<impuestos>'.$lineas.'</impuestos></comprobanteRetencion>';

        $this->post('/api/withholdings/import', [
            'company_id' => $this->company->id, 'xml' => UploadedFile::fake()->createWithContent('retencion.xml', $xml),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    /** Retención EMITIDA a un proveedor por una compra. */
    private function retencionEmitida(Purchase $compra, array $lineas): void
    {
        $this->postJson('/api/withholdings-emitted', ['company_id' => $this->company->id, 'purchase_id' => $compra->id, 'lineas' => $lineas])->assertCreated();
    }

    private function f104(?string $desde = null, ?string $hasta = null)
    {
        return $this->getJson('/api/taxes/formulario104?'.http_build_query([
            'company_id' => $this->company->id,
            'desde' => $desde ?? now()->startOfMonth()->toDateString(),
            'hasta' => $hasta ?? now()->endOfMonth()->toDateString(),
        ]))->assertOk();
    }

    private function clave(string $n): string
    {
        return str_pad($n, 49, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ el caso completo

    public function test_el_saldo_de_iva_suma_cada_linea_con_su_signo_y_ya_no_usa_la_formula_anterior(): void
    {
        $factura = $this->facturar(1000);                                    // IVA 150, total 1150
        $this->notaDeCredito($factura, 200);                                 // IVA 30, total 230 (sin emitir al SRI todavía)
        $compra = $this->comprar(400);                                       // IVA 60, total 460
        $this->retencionRecibida(renta: 10, iva: 45, clave: $this->clave('1')); // nos retuvieron 10 de renta y 45 de IVA
        $this->retencionEmitida($compra, [                                   // retuvimos 18 de IVA (30% de 60) y 4 de renta (10% de 40)
            ['tipo' => 'iva', 'codigo' => '721', 'base_imponible' => 60],
            ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 40],
        ]);

        $r = $this->f104();

        // Ventas: facturas, menos la nota de crédito = lo que se declara
        $this->assertEquals(1, $r->json('ventas.facturas.cantidad'));
        $this->assertEquals(1150.0, $r->json('ventas.facturas.total_ventas'));
        $this->assertEquals(1000.0, $r->json('ventas.facturas.ventas_12'));
        $this->assertEquals(150.0, $r->json('ventas.facturas.iva_cobrado'));
        $this->assertEquals(1, $r->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(230.0, $r->json('ventas.notas_credito.total'));
        $this->assertEquals(200.0, $r->json('ventas.notas_credito.ventas_12'));
        $this->assertEquals(30.0, $r->json('ventas.notas_credito.iva'));
        $this->assertEquals(1, $r->json('ventas.notas_credito.sin_emitir'), 'La nota del SRI guardada y aún sin emitir se avisa');
        $this->assertEquals(800.0, $r->json('ventas.ventas_12'));
        $this->assertEquals(800.0, $r->json('ventas.base_imponible_12'));
        $this->assertEquals(0.0, $r->json('ventas.ventas_0'));
        $this->assertEquals(120.0, $r->json('ventas.iva_cobrado'));
        $this->assertEquals(920.0, $r->json('ventas.total_ventas'));

        // Compras
        $this->assertEquals(60.0, $r->json('compras.iva_pagado'));
        $this->assertEquals(400.0, $r->json('compras.compras_12'));
        $this->assertEquals(460.0, $r->json('compras.total_compras'));

        // Retenciones: cada una en su casillero
        $this->assertEquals(55.0, $r->json('retenciones.recibidas'));
        $this->assertEquals(45.0, $r->json('retenciones.recibidas_iva'));
        $this->assertEquals(10.0, $r->json('retenciones.recibidas_renta'));
        $this->assertEquals(0.0, $r->json('retenciones.recibidas_otras'));
        $this->assertEquals(22.0, $r->json('retenciones.emitidas'));
        $this->assertEquals(18.0, $r->json('retenciones.emitidas_iva'));
        $this->assertEquals(4.0, $r->json('retenciones.emitidas_renta'));

        // 150 − 30 − 60 − 45 + 18 = 33 (no 57, que es lo que daba 150 − 60 − 55 + 22)
        $this->assertEquals(33.0, $r->json('saldo_iva'));
        $this->assertNotEquals(57.0, $r->json('saldo_iva'), 'La fórmula anterior ya no aparece');
        $this->assertSame('PAGAR', $r->json('declaracion'));

        // El cálculo línea por línea suma exactamente el saldo
        $c = $r->json('calculo_iva');
        $this->assertEquals(150.0, $c['iva_cobrado_facturas']);
        $this->assertEquals(30.0, $c['menos_iva_notas_credito']);
        $this->assertEquals(120.0, $c['iva_cobrado_neto']);
        $this->assertEquals(60.0, $c['menos_credito_tributario_compras']);
        $this->assertEquals(45.0, $c['menos_retenciones_iva_recibidas']);
        $this->assertEquals(18.0, $c['mas_retenciones_iva_emitidas']);
        $this->assertEquals(10.0, $c['retenciones_renta_recibidas_informativo']);
        $this->assertEquals(33.0, $c['a_pagar']);
        $this->assertEqualsWithDelta(
            $c['iva_cobrado_facturas'] - $c['menos_iva_notas_credito'] - $c['menos_credito_tributario_compras']
            - $c['menos_retenciones_iva_recibidas'] + $c['mas_retenciones_iva_emitidas'],
            $c['a_pagar'], 0.001,
        );
    }

    public function test_una_nota_de_credito_ya_emitida_no_se_avisa_como_pendiente(): void
    {
        $factura = $this->facturar(1000);
        $nota = $this->notaDeCredito($factura, 200);
        $this->postJson("/api/credit-notes/{$nota->id}/emit")->assertOk();

        $r = $this->f104();

        $this->assertEquals(0, $r->json('ventas.notas_credito.sin_emitir'));
        $this->assertEquals(120.0, $r->json('ventas.iva_cobrado'));
        $this->assertEquals(120.0, $r->json('saldo_iva'));
    }

    // ------------------------------------------------------------ retenciones

    public function test_las_retenciones_de_renta_recibidas_no_bajan_el_iva(): void
    {
        $this->facturar(1000);                                               // IVA 150
        $this->retencionRecibida(renta: 10, iva: 0, clave: $this->clave('2'));

        $r = $this->f104();

        $this->assertEquals(10.0, $r->json('retenciones.recibidas'));
        $this->assertEquals(10.0, $r->json('retenciones.recibidas_renta'));
        $this->assertEquals(0.0, $r->json('retenciones.recibidas_iva'));
        $this->assertEquals(150.0, $r->json('saldo_iva'), 'La retención de renta es crédito del impuesto a la renta, no del IVA');
        $this->assertEquals(10.0, $r->json('calculo_iva.retenciones_renta_recibidas_informativo'));
    }

    public function test_las_retenciones_de_iva_recibidas_si_bajan_el_iva(): void
    {
        $this->facturar(1000);
        $this->retencionRecibida(renta: 0, iva: 45, clave: $this->clave('3'));

        $this->assertEquals(105.0, $this->f104()->json('saldo_iva'));
    }

    public function test_las_retenciones_de_renta_emitidas_van_al_103_y_no_suman_al_saldo_del_104(): void
    {
        $this->facturar(1000);                                               // IVA 150
        $compra = $this->comprar(400);                                       // IVA 60
        $this->retencionEmitida($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 40]]);

        $r = $this->f104();

        $this->assertEquals(4.0, $r->json('retenciones.emitidas'));
        $this->assertEquals(4.0, $r->json('retenciones.emitidas_renta'));
        $this->assertEquals(0.0, $r->json('retenciones.emitidas_iva'));
        $this->assertEquals(90.0, $r->json('saldo_iva'), '150 − 60: la retención de renta que hicimos no se paga con el 104');
    }

    public function test_una_retencion_recibida_que_no_se_puede_clasificar_no_baja_el_iva_y_se_avisa(): void
    {
        $this->facturar(1000);
        Withholding::create(['company_id' => $this->company->id, 'tipo' => 'recibida', 'numero' => '001-001-000000777', 'fecha' => now()->toDateString(), 'total_retenido' => 7, 'xml' => 'no es un xml']);

        $r = $this->f104();

        $this->assertEquals(7.0, $r->json('retenciones.recibidas'));
        $this->assertEquals(7.0, $r->json('retenciones.recibidas_otras'));
        $this->assertEquals(0.0, $r->json('retenciones.recibidas_iva'));
        $this->assertEquals(150.0, $r->json('saldo_iva'));
    }

    // ------------------------------------------------------------ notas de crédito

    public function test_una_nota_de_credito_al_0_por_ciento_baja_las_ventas_0_y_no_el_iva(): void
    {
        $factura = $this->facturar(500, 0);                                  // venta al 0%
        $this->facturar(1000);                                               // venta al 15% (IVA 150)
        $this->notaDeCredito($factura, 100, 'sri', 0);

        $r = $this->f104();

        $this->assertEquals(400.0, $r->json('ventas.ventas_0'));
        $this->assertEquals(1000.0, $r->json('ventas.ventas_12'));
        $this->assertEquals(150.0, $r->json('ventas.iva_cobrado'));
        $this->assertEquals(1550.0, $r->json('ventas.total_ventas'), '500 + 1150 − 100');
    }

    public function test_la_nota_de_credito_interna_tambien_baja_las_ventas_como_la_factura_sin_comprobante(): void
    {
        $factura = $this->facturar(1000);
        $this->notaDeCredito($factura, 100, 'interna');                      // IVA 15

        $r = $this->f104();

        $this->assertEquals(1, $r->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(0, $r->json('ventas.notas_credito.sin_emitir'), 'Una nota interna nunca se emite');
        $this->assertEquals(135.0, $r->json('ventas.iva_cobrado'));
        $this->assertEquals(135.0, $r->json('saldo_iva'));
    }

    public function test_una_nota_anulada_o_rechazada_por_el_sri_no_cuenta(): void
    {
        $factura = $this->facturar(1000);
        $anulada = $this->notaDeCredito($factura, 100);
        $this->postJson("/api/credit-notes/{$anulada->id}/anular")->assertOk();
        $rechazada = $this->notaDeCredito($factura, 100);
        $this->postJson("/api/credit-notes/{$rechazada->id}/emit")->assertOk();
        SriDocument::where('tipo_comprobante', 'notaCredito')->update(['estado' => 'NO AUTORIZADO']);

        $r = $this->f104();

        $this->assertEquals(0, $r->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(150.0, $r->json('ventas.iva_cobrado'));
        $this->assertEquals(150.0, $r->json('saldo_iva'));
    }

    public function test_una_nota_de_otro_periodo_u_otra_empresa_no_cuenta(): void
    {
        $factura = $this->facturar(1000);
        $nota = $this->notaDeCredito($factura, 100);
        $nota->update(['fecha' => now()->startOfMonth()->subDay()->toDateString()]);       // el mes anterior
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'estab' => '001', 'pto_emi' => '001']);
        CreditNote::create(['company_id' => $otra->id, 'contact_id' => $this->cliente->id, 'tipo' => 'sri', 'motivo' => 'x', 'fecha' => now()->toDateString(),
            'total_sin_impuestos' => 50, 'total_impuesto' => 7.5, 'importe_total' => 57.5, 'saldo_disponible' => 0]);

        $r = $this->f104();

        $this->assertEquals(0, $r->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(150.0, $r->json('saldo_iva'));
        // Y la del mes anterior sí cuenta en el 104 de ese mes
        $anterior = $this->f104(now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString());
        $this->assertEquals(1, $anterior->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(-15.0, $anterior->json('saldo_iva'));
        $this->assertSame('DEVOLUCIÓN', $anterior->json('declaracion'));
    }

    // ------------------------------------------------------------ el período

    public function test_el_primer_y_el_ultimo_dia_del_periodo_entran_en_las_compras_y_las_retenciones(): void
    {
        $desde = now()->startOfMonth()->toDateString();
        $hasta = now()->endOfMonth()->toDateString();
        $this->comprar(100, $desde, '001-001-000000001');                    // IVA 15, el primer día
        $this->comprar(100, $hasta, '001-001-000000002');                    // IVA 15, el último día
        $this->comprar(100, now()->startOfMonth()->subDay()->toDateString(), '001-001-000000003'); // el mes anterior: fuera
        Withholding::create(['company_id' => $this->company->id, 'tipo' => 'emitida', 'numero' => '001-001-000000010', 'fecha' => $desde, 'total_retenido' => 3,
            'xml' => json_encode(['tipo' => 'iva', 'codigo' => '721'])]);
        Withholding::create(['company_id' => $this->company->id, 'tipo' => 'emitida', 'numero' => '001-001-000000011', 'fecha' => $hasta, 'total_retenido' => 2,
            'xml' => json_encode(['tipo' => 'iva', 'codigo' => '721'])]);

        $r = $this->f104($desde, $hasta);

        $this->assertEquals(2, $r->json('resumen.total_compras'));
        $this->assertEquals(30.0, $r->json('compras.iva_pagado'));
        $this->assertEquals(5.0, $r->json('retenciones.emitidas_iva'));
        $this->assertEquals(-25.0, $r->json('saldo_iva'), '0 − 30 + 5');
    }

    public function test_sin_notas_ni_retenciones_el_saldo_es_el_iva_cobrado_menos_el_pagado(): void
    {
        $this->facturar(1000);
        $this->comprar(400);

        $r = $this->f104();

        $this->assertEquals(90.0, $r->json('saldo_iva'));
        $this->assertEquals(0.0, $r->json('retenciones.recibidas'));
        $this->assertEquals(0.0, $r->json('retenciones.emitidas'));
        $this->assertEquals(0, $r->json('ventas.notas_credito.cantidad'));
        $this->assertEquals(1150.0, $r->json('ventas.total_ventas'));
    }

    public function test_el_resumen_anual_del_ats_conserva_sus_llaves(): void
    {
        $this->facturar(1000);
        $this->comprar(400);

        $r = $this->getJson('/api/taxes/ats?'.http_build_query(['company_id' => $this->company->id, 'anio' => (int) now()->format('Y')]))->assertOk();

        $mes = $r->json('ventas_mensuales.'.(int) now()->format('n'));
        $this->assertEquals(150.0, $mes['iva_cobrado']);
        $this->assertEquals(150.0, $r->json('resumen_anual.total_iva_cobrado'));
        $this->assertEquals(60.0, $r->json('resumen_anual.total_iva_pagado'));
        $this->assertEquals(90.0, $r->json('resumen_anual.saldo_iva_anual'));
    }
}
