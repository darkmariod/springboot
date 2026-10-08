<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Advance;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.1: estado de cuenta de clientes y de proveedores.
 *
 * Lo que pidió el consultor: por cada factura, el número de documento, la nota de crédito que la afecta, la
 * retención y cuánto me debe; y un segundo reporte con el total por cliente. El ejemplo: factura $100 (0% IVA),
 * nota de crédito $10, retención recibida $1 => el cliente debe $89, en el documento, en el resumen y en el
 * libro mayor (cuentas por cobrar).
 *
 * La regla de fondo es la consistencia: el saldo de cada documento es el saldo_pendiente guardado, la suma por
 * cliente es la de sus facturas, y el desglose (total + notas de débito − notas de crédito − retenciones −
 * cobros − anticipos) tiene que dar exactamente ese saldo. `assertConsistente()` lo revisa en cada escenario.
 */
class EstadoCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $clienteRel;
    private Contact $ambos;   // cliente y proveedor a la vez
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Estado de Cuenta SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        $this->clienteRel = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002',
            'razon_social' => 'Cliente Relacionado', 'parte_relacionada' => true,
        ]);
        $this->ambos = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Cliente y Proveedor SA', 'es_cliente' => true, 'es_proveedor' => true,
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ============================================================ el ejemplo del consultor

    public function test_factura_100_nota_10_y_retencion_1_dejan_89_en_el_documento_el_resumen_y_el_libro(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->nota($factura, 'interna', 10, 0)->assertCreated();
        $this->importarRetencion($factura->numero, 1.0, '1')->assertCreated();
        $this->assertEquals(89.0, (float) $factura->fresh()->saldo_pendiente);

        // 1) El documento: número, la nota de crédito que la afecta, la retención y cuánto debe
        $detalle = $this->detalle($this->cliente)->assertOk();
        $doc = $detalle->json('documentos.0');
        $this->assertCount(1, $detalle->json('documentos'));
        $this->assertSame($factura->numero, $doc['numero']);
        $this->assertEquals(100.0, $doc['total']);
        $this->assertCount(1, $doc['notas_credito']);
        $this->assertSame('NCI-000001', $doc['notas_credito'][0]['numero']);
        $this->assertEquals(10.0, $doc['notas_credito'][0]['aplicado']);
        $this->assertCount(1, $doc['retenciones']);
        $this->assertSame('001-001-000000001', $doc['retenciones'][0]['numero']);
        $this->assertEquals(1.0, $doc['retenciones'][0]['valor']);
        $this->assertEquals(89.0, $doc['saldo'], 'El cliente debe 100 - 10 - 1 en el documento');

        // 2) El resumen por cliente
        $resumen = $this->resumen()->assertOk();
        $fila = $resumen->json('clientes.0');
        $this->assertSame($this->cliente->id, $fila['contact_id']);
        $this->assertEquals(100.0, $fila['facturado']);
        $this->assertEquals(10.0, $fila['notas_credito']);
        $this->assertEquals(1.0, $fila['retenciones']);
        $this->assertEquals(0.0, $fila['cobros']);
        $this->assertEquals(89.0, $fila['saldo'], 'El cliente debe 89 en el resumen');
        $this->assertEquals(89.0, $fila['saldo_neto']);
        $this->assertEquals(89.0, $resumen->json('totales.saldo'));

        // 3) El libro mayor de cuentas por cobrar dice lo mismo, y el chequeo estricto queda en cero
        $this->assertEquals(89.0, $this->saldoCuentas(['1.1.03', '1.1.09'], 1), 'El libro mayor debe 89');
        $this->chequeoEstricto()
            ->expectsOutputToContain('OK  Cuentas por cobrar (1.1.03): facturas pendientes − notas de crédito disponibles $89.00 = mayor $89.00')
            ->assertExitCode(0);

        // Los movimientos, en orden, terminan en lo mismo: factura +100, nota -10, retención -1
        $mov = $detalle->json('movimientos');
        $this->assertSame(['factura', 'nota_credito', 'retencion'], array_column($mov, 'tipo'));
        $this->assertEquals([100.0, 90.0, 89.0], array_column($mov, 'saldo'));
        $this->assertEquals([100.0, 0.0, 0.0], array_column($mov, 'cargo'));
        $this->assertEquals([0.0, 10.0, 1.0], array_column($mov, 'abono'));

        $this->assertConsistente();
    }

    // ============================================================ notas de crédito: sin doble conteo

    public function test_nota_de_credito_emitida_y_luego_aplicada_a_otra_factura_cuenta_una_sola_vez(): void
    {
        $a = $this->facturar($this->cliente, 100, 0);
        $this->cobrar($a, 100);                                   // A queda cobrada
        $this->nota($a, 'interna', 10, 0)->assertCreated();       // no hay saldo que bajar: 10 quedan a favor
        $nota = CreditNote::firstOrFail();
        $this->assertEquals(10.0, (float) $nota->saldo_disponible);

        // Antes de usarla: el cliente tiene 10 a favor y la nota no baja ninguna factura
        $antes = $this->resumen()->json('clientes.0');
        $this->assertEquals(0.0, $antes['saldo']);
        $this->assertEquals(0.0, $antes['notas_credito']);
        $this->assertEquals(10.0, $antes['saldo_favor_nc']);
        $this->assertEquals(-10.0, $antes['saldo_neto']);
        $this->assertConsistente();

        // Se usa en otra factura: ahora baja el saldo de B y ya no queda a favor
        $b = $this->facturar($this->cliente, 50, 0);
        $this->postJson("/api/credits/apply/{$b->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertOk();

        $detalle = $this->detalle($this->cliente)->assertOk();
        [$docA, $docB] = $detalle->json('documentos');
        $this->assertSame('NCI-000001', $docA['notas_credito'][0]['numero']);
        $this->assertEquals(10.0, $docA['notas_credito'][0]['total']);
        $this->assertEquals(0.0, $docA['notas_credito'][0]['aplicado'], 'En su propia factura no bajó nada');
        $this->assertEquals(0.0, $docA['notas_credito_total']);
        $this->assertSame('saldo_favor', $docB['notas_credito'][0]['via']);
        $this->assertSame('NCI-000001', $docB['notas_credito'][0]['numero']);
        $this->assertEquals(10.0, $docB['notas_credito_total']);
        $this->assertEquals(40.0, $docB['saldo']);

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(10.0, $fila['notas_credito'], 'La nota cuenta 10 una sola vez, no 20');
        $this->assertEquals(0.0, $fila['saldo_favor_nc']);
        $this->assertEquals(40.0, $fila['saldo']);
        $this->assertEquals(40.0, $fila['saldo_neto']);
        $this->assertConsistente();
    }

    public function test_nota_de_credito_mayor_que_el_saldo_deja_el_excedente_como_saldo_a_favor(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->cobrar($factura, 95);                              // quedan 5 por cobrar
        $this->nota($factura, 'interna', 10, 0)->assertCreated(); // 5 bajan la factura y 5 quedan a favor

        $detalle = $this->detalle($this->cliente)->assertOk();
        $doc = $detalle->json('documentos.0');
        $this->assertEquals(10.0, $doc['notas_credito'][0]['total']);
        $this->assertEquals(5.0, $doc['notas_credito'][0]['aplicado']);
        $this->assertEquals(0.0, $doc['saldo']);
        $this->assertEquals(5.0, $detalle->json('notas_credito_a_favor.0.saldo'));
        $this->assertSame('NCI-000001', $detalle->json('notas_credito_a_favor.0.numero'));

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(0.0, $fila['saldo']);
        $this->assertEquals(5.0, $fila['saldo_favor_nc']);
        $this->assertEquals(-5.0, $fila['saldo_neto'], 'Con la nota, la empresa le debe 5 al cliente');
        $this->assertConsistente();
    }

    // ============================================================ cobros, contado, ND, anticipos

    public function test_cobro_parcial_baja_el_saldo_y_muestra_la_forma_de_pago(): void
    {
        $factura = $this->facturar($this->cliente, 100, 15);      // 115
        $this->cobrar($factura, 40);

        $doc = $this->detalle($this->cliente)->json('documentos.0');
        $this->assertEquals(115.0, $doc['total']);
        $this->assertCount(1, $doc['cobros']);
        $this->assertEquals(40.0, $doc['cobros'][0]['monto']);
        $this->assertSame('efectivo', $doc['cobros'][0]['forma']);
        $this->assertSame('Efectivo', $doc['cobros'][0]['forma_label']);
        $this->assertEquals(75.0, $doc['saldo']);

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(115.0, $fila['facturado']);
        $this->assertEquals(40.0, $fila['cobros']);
        $this->assertEquals(75.0, $fila['saldo']);
        $this->assertConsistente();
    }

    public function test_una_factura_al_contado_sale_cobrada_y_sin_saldo(): void
    {
        $this->facturar($this->cliente, 100, 15, 'efectivo');

        $doc = $this->detalle($this->cliente)->json('documentos.0');
        $this->assertEquals(115.0, $doc['total']);
        $this->assertEquals(115.0, $doc['cobros_total']);
        $this->assertSame('Cobro al contado', $doc['cobros'][0]['detalle']);
        $this->assertEquals(0.0, $doc['saldo']);

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(115.0, $fila['facturado']);
        $this->assertEquals(115.0, $fila['cobros']);
        $this->assertEquals(0.0, $fila['saldo']);
        $this->assertConsistente();
    }

    public function test_las_notas_de_debito_suben_el_saldo_y_se_listan_con_su_numero(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->debito($factura, 'interna', 5)->assertCreated();
        $this->debito($factura, 'sri', 3)->assertCreated();

        $doc = $this->detalle($this->cliente)->json('documentos.0');
        $this->assertCount(2, $doc['notas_debito'], 'Las notas de débito no salen como documentos aparte: cargan valor a su factura');
        $this->assertSame(['NDI-000001', '001-001-000000002'], array_column($doc['notas_debito'], 'numero'));
        $this->assertSame([true, false], array_column($doc['notas_debito'], 'interna'));
        $this->assertEquals(8.0, $doc['notas_debito_total']);
        $this->assertEquals(108.0, $doc['saldo']);

        $fila = $this->resumen()->json('clientes.0');
        $this->assertSame(1, $fila['documentos']);
        $this->assertEquals(100.0, $fila['facturado']);
        $this->assertEquals(8.0, $fila['notas_debito']);
        $this->assertEquals(108.0, $fila['saldo']);
        $this->assertConsistente();
    }

    public function test_anticipos_de_cliente_aplicados_y_sin_aplicar_salen_por_separado(): void
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'monto' => 50, 'forma_pago' => 'efectivo',
        ])->assertCreated();
        $anticipo = Advance::firstOrFail();
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->postJson("/api/credits/apply/{$factura->id}", ['tipo' => 'anticipo', 'id' => $anticipo->id, 'monto' => 20])->assertOk();

        $detalle = $this->detalle($this->cliente)->assertOk();
        $this->assertEquals(20.0, $detalle->json('documentos.0.anticipos_total'));
        $this->assertSame('ANT-'.$anticipo->id, $detalle->json('documentos.0.anticipos.0.documento'));
        $this->assertEquals(80.0, $detalle->json('documentos.0.saldo'));
        $this->assertEquals(30.0, $detalle->json('anticipos_sin_aplicar.0.saldo'));
        $this->assertEquals(50.0, $detalle->json('anticipos_sin_aplicar.0.monto'));
        $this->assertEquals(50.0, $detalle->json('resumen.saldo_neto'), 'Saldo 80 menos 30 de anticipo todavía sin aplicar');
        $this->assertContains('anticipo', array_column($detalle->json('movimientos'), 'tipo'));

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(20.0, $fila['anticipos_aplicados']);
        $this->assertEquals(30.0, $fila['anticipos_sin_aplicar']);
        $this->assertEquals(80.0, $fila['saldo']);
        $this->assertEquals(50.0, $fila['saldo_neto']);
        $this->assertConsistente();
    }

    public function test_un_cliente_que_solo_tiene_un_anticipo_sin_usar_tambien_sale_en_el_resumen(): void
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'monto' => 40, 'forma_pago' => 'efectivo',
        ])->assertCreated();

        $fila = $this->resumen()->assertOk()->json('clientes.0');
        $this->assertSame($this->cliente->id, $fila['contact_id']);
        $this->assertEquals(0.0, $fila['facturado']);
        $this->assertEquals(40.0, $fila['anticipos_sin_aplicar']);
        $this->assertEquals(-40.0, $fila['saldo_neto']);
        $this->assertConsistente();
    }

    // ============================================================ cruce de saldos

    public function test_el_cruce_de_saldos_sale_como_cobro_del_cliente_y_como_pago_del_proveedor(): void
    {
        $factura = $this->facturar($this->ambos, 100, 15);        // 115
        $compra = $this->comprar($this->ambos, 100);              // 115
        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $compra->id]],
        ])->assertOk();

        // Cliente: el cruce es un cobro
        $doc = $this->detalle($this->ambos)->assertOk()->json('documentos.0');
        $this->assertSame('cruce_saldos', $doc['cobros'][0]['forma']);
        $this->assertEquals(50.0, $doc['cobros'][0]['monto']);
        $this->assertEquals(50.0, $doc['cruces_total']);
        $this->assertEquals(65.0, $doc['saldo']);
        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(50.0, $fila['cobros']);
        $this->assertEquals(65.0, $fila['saldo']);
        $this->assertContains('cruce', array_column($this->detalle($this->ambos)->json('movimientos'), 'tipo'));

        // Proveedor: el mismo cruce es un pago, con la factura contra la que se cruzó
        $detalleProv = $this->detalleProveedor($this->ambos)->assertOk();
        $compraDoc = $detalleProv->json('documentos.0');
        $this->assertSame($compra->numero, $compraDoc['numero']);
        $this->assertSame('cruce_saldos', $compraDoc['pagos'][0]['forma']);
        $this->assertSame($factura->numero, $compraDoc['pagos'][0]['documento']);
        $this->assertEquals(50.0, $compraDoc['cruces_total']);
        $this->assertEquals(65.0, $compraDoc['saldo']);
        $this->assertSame('cruce', collect($detalleProv->json('movimientos'))->firstWhere('abono', 50.0)['tipo']);
        $filaProv = $this->resumenProveedores()->json('proveedores.0');
        $this->assertEquals(50.0, $filaProv['pagos']);
        $this->assertEquals(65.0, $filaProv['saldo']);

        $this->assertConsistente();
    }

    // ============================================================ anulados, parte relacionada, otra empresa

    public function test_los_documentos_anulados_no_salen_y_su_saldo_vuelve(): void
    {
        $a = $this->facturar($this->cliente, 100, 0);

        $this->nota($a, 'interna', 10, 0)->assertCreated();
        $this->assertEquals(90.0, $this->detalle($this->cliente)->json('documentos.0.saldo'));
        $this->postJson('/api/credit-notes/'.CreditNote::firstOrFail()->id.'/anular')->assertOk();
        $doc = $this->detalle($this->cliente)->json('documentos.0');
        $this->assertSame([], $doc['notas_credito'], 'Una nota anulada no se cuenta');
        $this->assertEquals(100.0, $doc['saldo']);

        $this->debito($a, 'interna', 5)->assertCreated();
        $this->assertEquals(105.0, $this->detalle($this->cliente)->json('documentos.0.saldo'));
        $this->postJson('/api/notas-debito/'.Invoice::where('tipo_comprobante', 'nota_debito_interna')->firstOrFail()->id.'/anular')->assertOk();
        $doc = $this->detalle($this->cliente)->json('documentos.0');
        $this->assertSame([], $doc['notas_debito'], 'Una nota de débito anulada no se cuenta');
        $this->assertEquals(100.0, $doc['saldo']);

        $b = $this->facturar($this->cliente, 50, 0);
        $this->postJson("/api/invoices/{$b->id}/anular")->assertOk();

        $detalle = $this->detalle($this->cliente)->assertOk();
        $this->assertCount(1, $detalle->json('documentos'), 'La factura anulada no sale');
        $this->assertSame($a->numero, $detalle->json('documentos.0.numero'));
        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(100.0, $fila['facturado']);
        $this->assertEquals(100.0, $fila['saldo']);
        $this->assertConsistente();
    }

    public function test_un_cliente_parte_relacionada_sale_igual_y_cuadra_con_su_cuenta_del_libro(): void
    {
        $factura = $this->facturar($this->clienteRel, 100, 15);   // 115 en 1.1.09
        $this->nota($factura, 'interna', 10, 0)->assertCreated();
        $this->cobrar($factura, 20);

        $doc = $this->detalle($this->clienteRel)->assertOk()->json('documentos.0');
        $this->assertEquals(85.0, $doc['saldo']);
        $this->assertEquals(10.0, $doc['notas_credito_total']);
        $this->assertEquals(20.0, $doc['cobros_total']);

        $this->assertEquals(85.0, $this->saldoCuentas(['1.1.09'], 1), 'La cuenta de relacionadas del libro dice lo mismo');
        $this->assertEquals(0.0, $this->saldoCuentas(['1.1.03'], 1));
        $this->assertConsistente();
    }

    public function test_un_cliente_de_otra_empresa_no_se_filtra(): void
    {
        $this->facturar($this->cliente, 100, 0);

        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000009', 'razon_social' => 'Cliente Ajeno']);
        Invoice::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'numero' => '001-001-000000999', 'items' => [],
            'total_sin_impuestos' => 777, 'total_impuesto' => 0, 'importe_total' => 777, 'forma_pago' => 'credito', 'saldo_pendiente' => 777,
            'estado' => 'emitida', 'fecha_emision' => now()]);
        Advance::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'fecha' => now(), 'monto' => 55, 'saldo' => 55, 'forma_pago' => 'efectivo', 'tipo' => 'cliente']);

        // El resumen de esta empresa no trae nada de la otra
        $resumen = $this->resumen()->assertOk();
        $this->assertSame([$this->cliente->id], array_column($resumen->json('clientes'), 'contact_id'));
        $this->assertEquals(100.0, $resumen->json('totales.saldo'));
        $this->assertEquals(0.0, $resumen->json('totales.anticipos_sin_aplicar'));
        $this->assertStringNotContainsString('Cliente Ajeno', $resumen->getContent());

        // Pedir el detalle de un cliente ajeno con el id de esta empresa (o al revés) no da ningún dato
        $this->detalle($ajeno)->assertNotFound();
        $this->getJson("/api/estado-cuenta/clientes/{$this->cliente->id}?company_id={$otra->id}")->assertNotFound();
        $this->getJson("/api/estado-cuenta/proveedores/{$ajeno->id}?company_id={$this->company->id}")->assertNotFound();

        // Y la otra empresa ve solo lo suyo
        $suyo = $this->getJson("/api/estado-cuenta/clientes?company_id={$otra->id}")->assertOk();
        $this->assertSame([$ajeno->id], array_column($suyo->json('clientes'), 'contact_id'));
        $this->assertEquals(777.0, $suyo->json('totales.saldo'));
        $this->assertEquals(55.0, $suyo->json('totales.anticipos_sin_aplicar'));
    }

    // ============================================================ antigüedad, filtros, búsqueda

    public function test_la_antiguedad_reparte_el_saldo_por_dias_desde_la_emision(): void
    {
        foreach ([10, 45, 75, 120] as $dias) {
            $f = $this->facturar($this->cliente, 10, 0);
            $f->update(['fecha_emision' => now()->subDays($dias)]);
        }

        $fila = $this->resumen()->assertOk()->json('clientes.0');
        $this->assertEquals(['0-30' => 10, '31-60' => 10, '61-90' => 10, '90+' => 10], $fila['antiguedad']);
        $this->assertEquals(40.0, $fila['saldo']);
        $this->assertEquals(['0-30' => 10, '31-60' => 10, '61-90' => 10, '90+' => 10], $this->resumen()->json('totales.antiguedad'));
        $this->assertSame(['0-30', '31-60', '61-90', '90+'], $this->resumen()->json('tramos'));

        $docs = $this->detalle($this->cliente)->json('documentos');
        $this->assertSame([120, 75, 45, 10], array_column($docs, 'dias'));
        $this->assertSame(['90+', '61-90', '31-60', '0-30'], array_column($docs, 'tramo'));
        $this->assertConsistente();
    }

    public function test_solo_la_deuda_vigente_entra_en_la_antiguedad(): void
    {
        $vieja = $this->facturar($this->cliente, 100, 0);
        $vieja->update(['fecha_emision' => now()->subDays(100)]);
        $this->cobrar($vieja, 100);                                // pagada: ya no es deuda de más de 90 días
        $reciente = $this->facturar($this->cliente, 30, 0);

        $fila = $this->resumen()->json('clientes.0');
        $this->assertEquals(['0-30' => 30, '31-60' => 0, '61-90' => 0, '90+' => 0], $fila['antiguedad']);
        $this->assertEquals(30.0, $fila['saldo']);
        $this->assertSame($reciente->numero, $this->detalle($this->cliente)->json('documentos.1.numero'));
    }

    public function test_filtra_por_fechas_busca_y_pide_solo_con_saldo(): void
    {
        $enero = $this->facturar($this->cliente, 100, 0);
        $enero->update(['fecha_emision' => '2026-01-15 10:00:00']);
        $marzo = $this->facturar($this->cliente, 40, 0);
        $marzo->update(['fecha_emision' => '2026-03-10 10:00:00']);
        $this->facturar($this->clienteRel, 25, 0, 'efectivo');    // al contado: sin saldo

        // Fechas: solo las facturas emitidas en el rango
        $r = $this->resumen(['desde' => '2026-03-01', 'hasta' => '2026-03-31'])->assertOk();
        $this->assertEquals(40.0, $r->json('totales.saldo'));
        $this->assertSame([$this->cliente->id], array_column($r->json('clientes'), 'contact_id'));
        $docs = $this->detalle($this->cliente, ['desde' => '2026-03-01', 'hasta' => '2026-03-31'])->json('documentos');
        $this->assertSame([$marzo->numero], array_column($docs, 'numero'));
        $this->resumen(['desde' => '2026-03-31', 'hasta' => '2026-03-01'])->assertStatus(422);

        // Búsqueda por nombre y por identificación
        $this->assertSame([$this->clienteRel->id], array_column($this->resumen(['buscar' => 'Relacionado'])->json('clientes'), 'contact_id'));
        $this->assertSame([$this->cliente->id], array_column($this->resumen(['buscar' => '1700000001'])->json('clientes'), 'contact_id'));
        $this->assertSame([], $this->resumen(['buscar' => 'no existe'])->json('clientes'));

        // Solo con saldo: el cliente que pagó al contado no sale, los totales no cambian
        $todos = $this->resumen()->json('clientes');
        $this->assertCount(2, $todos);
        $conSaldo = $this->resumen(['con_saldo' => 1])->json('clientes');
        $this->assertSame([$this->cliente->id], array_column($conSaldo, 'contact_id'));
        $this->assertEquals(140.0, $this->resumen(['con_saldo' => 1])->json('totales.saldo'));
        $this->assertConsistente();
    }

    public function test_el_resumen_trae_el_total_general_de_todas_las_columnas(): void
    {
        $a = $this->facturar($this->cliente, 100, 0);
        $this->nota($a, 'interna', 10, 0)->assertCreated();
        $this->cobrar($a, 30);
        $b = $this->facturar($this->clienteRel, 50, 0);
        $this->debito($b, 'interna', 5)->assertCreated();

        $t = $this->resumen()->assertOk()->json('totales');
        $this->assertSame(2, $t['documentos']);
        $this->assertEquals(150.0, $t['facturado']);
        $this->assertEquals(5.0, $t['notas_debito']);
        $this->assertEquals(10.0, $t['notas_credito']);
        $this->assertEquals(30.0, $t['cobros']);
        $this->assertEquals(115.0, $t['saldo']);     // (100 - 10 - 30) + (50 + 5)
        $this->assertEquals(115.0, $t['saldo_neto']);
        $this->assertEquals(0.0, $t['ajustes']);
        $this->assertConsistente();
    }

    // ============================================================ si el saldo guardado no cuadra, se ve

    public function test_una_diferencia_con_el_saldo_guardado_sale_como_otros_ajustes_y_nunca_cambia_el_saldo(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        // Un dato raro: el saldo guardado baja sin que exista ningún cobro, nota ni retención
        $factura->update(['saldo_pendiente' => 95]);

        $detalle = $this->detalle($this->cliente)->assertOk();
        $doc = $detalle->json('documentos.0');
        $this->assertEquals(95.0, $doc['saldo'], 'El saldo mostrado es siempre el guardado');
        $this->assertEquals(-5.0, $doc['ajustes']);
        $mov = $detalle->json('movimientos');
        $this->assertSame('ajuste', end($mov)['tipo']);
        $this->assertEquals(95.0, end($mov)['saldo'], 'Los movimientos cierran en el saldo guardado');
        $this->assertEquals(-5.0, $this->resumen()->json('clientes.0.ajustes'));
        $this->assertEquals(95.0, $this->resumen()->json('totales.saldo'));
    }

    // ============================================================ proveedores

    public function test_proveedor_compra_con_retencion_pagos_anticipo_y_cruce_cuadra_con_cuentas_por_pagar(): void
    {
        $compra = $this->comprar($this->ambos, 100);              // 100 + IVA 15 = 115
        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100]],   // 10% = 10
        ])->assertCreated();
        $this->postJson("/api/payables/{$compra->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 30]]])->assertOk();
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->ambos->id, 'tipo' => 'proveedor', 'monto' => 30, 'forma_pago' => 'efectivo',
        ])->assertCreated();
        $anticipo = Advance::where('tipo', 'proveedor')->firstOrFail();
        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->id, 'monto' => 20])->assertOk();
        $factura = $this->facturar($this->ambos, 100, 15);
        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 15, 'documento_cruce' => $factura->id]],
        ])->assertOk();
        $this->assertEquals(40.0, (float) $compra->fresh()->saldo_pendiente);   // 115 - 10 - 30 - 20 - 15

        $detalle = $this->detalleProveedor($this->ambos)->assertOk();
        $doc = $detalle->json('documentos.0');
        $this->assertSame($compra->numero, $doc['numero']);
        $this->assertEquals(115.0, $doc['total']);
        $this->assertSame('001-001-000000001', $doc['retenciones'][0]['numero']);
        $this->assertEquals(10.0, $doc['retenciones'][0]['valor']);
        $this->assertEquals(10.0, $doc['retenciones_total']);
        $this->assertEquals(45.0, $doc['pagos_total'], 'Pago 30 + cruce 15');
        $this->assertEquals(15.0, $doc['cruces_total']);
        $this->assertEquals(20.0, $doc['anticipos_total']);
        $this->assertSame('ANT-'.$anticipo->id, $doc['anticipos'][0]['documento']);
        $this->assertEquals(40.0, $doc['saldo']);
        $this->assertEquals(0.0, $doc['ajustes']);

        // El anticipo tenía 30 y se usaron 20: quedan 10 sin aplicar, aparte del saldo
        $this->assertEquals(10.0, $detalle->json('anticipos_sin_aplicar.0.saldo'));
        $this->assertEquals(30.0, $detalle->json('resumen.saldo_neto'));
        $tipos = array_column($detalle->json('movimientos'), 'tipo');
        $this->assertEqualsCanonicalizing(['compra', 'retencion', 'pago', 'anticipo', 'cruce'], $tipos);
        $mov = $detalle->json('movimientos');
        $this->assertEquals(40.0, end($mov)['saldo']);

        $fila = $this->resumenProveedores()->assertOk()->json('proveedores.0');
        $this->assertSame($this->ambos->id, $fila['contact_id']);
        $this->assertEquals(115.0, $fila['comprado']);
        $this->assertEquals(10.0, $fila['retenciones']);
        $this->assertEquals(45.0, $fila['pagos']);
        $this->assertEquals(20.0, $fila['anticipos_aplicados']);
        $this->assertEquals(40.0, $fila['saldo']);
        $this->assertEquals(10.0, $fila['anticipos_sin_aplicar']);
        $this->assertEquals(30.0, $fila['saldo_neto']);
        $this->assertEquals(40.0, $this->resumenProveedores()->json('totales.saldo'));

        // El libro mayor: cuentas por pagar 40 y anticipos a proveedores 10
        $this->assertEquals(40.0, $this->saldoCuentas(['2.1.01', '2.1.11'], -1));
        $this->assertEquals(10.0, $this->saldoCuentas(['1.1.10'], 1));
        $this->assertConsistente();
    }

    public function test_proveedor_la_antiguedad_filtros_y_otra_empresa(): void
    {
        $vieja = $this->comprar($this->ambos, 100, 0, now()->subDays(50)->toDateString());   // 100
        $reciente = $this->comprar($this->ambos, 20, 0);                                    // 20

        $fila = $this->resumenProveedores()->assertOk()->json('proveedores.0');
        $this->assertEquals(['0-30' => 20, '31-60' => 100, '61-90' => 0, '90+' => 0], $fila['antiguedad']);
        $this->assertEquals(120.0, $fila['saldo']);
        $docs = $this->detalleProveedor($this->ambos)->json('documentos');
        $this->assertSame([$vieja->numero, $reciente->numero], array_column($docs, 'numero'));
        $this->assertSame(['31-60', '0-30'], array_column($docs, 'tramo'));

        // Fechas y búsqueda
        $solo = $this->resumenProveedores(['desde' => now()->subDays(5)->toDateString()])->json('proveedores.0');
        $this->assertEquals(20.0, $solo['saldo']);
        $this->assertSame([], $this->resumenProveedores(['buscar' => 'inexistente'])->json('proveedores'));

        // Otra empresa
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '04', 'identificacion' => '1790099887001', 'razon_social' => 'Proveedor Ajeno', 'es_proveedor' => true]);
        Purchase::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'numero' => '001-001-000000500', 'fecha_emision' => now(), 'items' => [],
            'total_sin_impuestos' => 900, 'total_impuesto' => 0, 'importe_total' => 900, 'saldo_pendiente' => 900]);
        $this->assertSame([$this->ambos->id], array_column($this->resumenProveedores()->json('proveedores'), 'contact_id'));
        $this->assertEquals(120.0, $this->resumenProveedores()->json('totales.saldo'));
        $this->detalleProveedor($ajeno)->assertNotFound();
        $this->assertConsistente();
    }

    // ============================================================ exportar

    public function test_exporta_el_resumen_de_clientes_a_excel_y_pdf(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->nota($factura, 'interna', 10, 0)->assertCreated();
        $this->importarRetencion($factura->numero, 1.0, '1')->assertCreated();

        $excel = $this->get('/api/estado-cuenta/clientes?'.http_build_query(['company_id' => $this->company->id, 'formato' => 'excel']))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $excel->headers->get('content-type'));
        $this->assertStringContainsString('estado-cuenta-clientes.csv', (string) $excel->headers->get('content-disposition'));
        $csv = $excel->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Con marca UTF-8 para que Excel muestre las tildes');
        $this->assertStringContainsString('"Cliente","Identificación","Facturado"', $csv);
        $this->assertStringContainsString('"Cliente Normal","1700000001","100.00","0.00","10.00","1.00","0.00","89.00","89.00","0.00","0.00","0.00","0.00","0.00","89.00"', $csv);
        $this->assertStringContainsString('"TOTAL","","100.00","0.00","10.00","1.00","0.00","89.00"', $csv);

        $pdf = $this->get('/api/estado-cuenta/clientes?'.http_build_query(['company_id' => $this->company->id, 'formato' => 'pdf']))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        $this->get('/api/estado-cuenta/clientes?'.http_build_query(['company_id' => $this->company->id, 'formato' => 'xml']))->assertStatus(422);
    }

    public function test_exporta_el_detalle_de_un_cliente_con_documentos_y_movimientos(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->nota($factura, 'interna', 10, 0)->assertCreated();
        $this->importarRetencion($factura->numero, 1.0, '1')->assertCreated();
        $base = ['company_id' => $this->company->id, 'formato' => 'excel'];

        $docs = $this->get("/api/estado-cuenta/clientes/{$this->cliente->id}?".http_build_query($base))->assertOk();
        $this->assertStringContainsString('estado-cuenta-cliente-cliente-normal.csv', (string) $docs->headers->get('content-disposition'));
        $csv = $docs->getContent();
        $this->assertStringContainsString('"Fecha","Factura","Total","Notas de crédito","Notas de débito","Retenciones","Cobros","Anticipos","Saldo","Días"', $csv);
        $this->assertStringContainsString('"'.$factura->numero.'","100.00","NCI-000001 (10.00)","","001-001-000000001 (1.00)","0.00","0.00","89.00"', $csv);
        $this->assertStringContainsString('"","TOTAL","100.00","10.00","0.00","1.00","0.00","0.00","89.00"', $csv);
        $this->assertStringContainsString('"","SALDO NETO (saldo − saldos a favor)"', $csv);

        $mov = $this->get("/api/estado-cuenta/clientes/{$this->cliente->id}?".http_build_query($base + ['vista' => 'movimientos']))->assertOk();
        $this->assertStringContainsString('-movimientos.csv', (string) $mov->headers->get('content-disposition'));
        $csv = $mov->getContent();
        $this->assertStringContainsString('"Fecha","Tipo","Documento","Factura","Detalle","Cargo","Abono","Saldo"', $csv);
        $this->assertStringContainsString('"Nota de crédito","NCI-000001"', $csv);
        $this->assertStringContainsString('"","","","","SALDO","","","89.00"', $csv);

        $pdf = $this->get("/api/estado-cuenta/clientes/{$this->cliente->id}?".http_build_query(['company_id' => $this->company->id, 'formato' => 'pdf']))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }

    public function test_exporta_proveedores_a_excel_y_pdf(): void
    {
        $compra = $this->comprar($this->ambos, 100);
        $this->postJson("/api/payables/{$compra->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 15]]])->assertOk();

        $csv = $this->get('/api/estado-cuenta/proveedores?'.http_build_query(['company_id' => $this->company->id, 'formato' => 'excel']))->assertOk()->getContent();
        $this->assertStringContainsString('"Proveedor","Identificación","Compras","Retenciones","Pagos"', $csv);
        $this->assertStringContainsString('"Cliente y Proveedor SA","1790011223001","115.00","0.00","15.00","0.00","100.00"', $csv);

        $detalle = $this->get("/api/estado-cuenta/proveedores/{$this->ambos->id}?".http_build_query(['company_id' => $this->company->id, 'formato' => 'excel']))->assertOk()->getContent();
        $this->assertStringContainsString('"Fecha","Compra","Total","Retenciones","Pagos","Anticipos aplicados","Saldo","Días"', $detalle);
        $this->assertStringContainsString('"'.$compra->numero.'","115.00","","15.00","0.00","100.00"', $detalle);

        foreach (['/api/estado-cuenta/proveedores', "/api/estado-cuenta/proveedores/{$this->ambos->id}"] as $url) {
            $pdf = $this->get($url.'?'.http_build_query(['company_id' => $this->company->id, 'formato' => 'pdf']))->assertOk();
            $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        }
    }

    public function test_la_empresa_es_obligatoria_y_debe_existir(): void
    {
        $this->getJson('/api/estado-cuenta/clientes')->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->getJson('/api/estado-cuenta/proveedores?company_id=99999')->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    // ============================================================ todo junto

    public function test_con_todo_mezclado_el_estado_de_cuenta_cuadra_con_las_facturas_y_el_libro(): void
    {
        // Cliente normal: crédito + nota + retención + cobro parcial + nota de débito
        $a = $this->facturar($this->cliente, 200, 15);            // 230
        $this->nota($a, 'interna', 20, 15)->assertCreated();       // 23
        $this->importarRetencion($a->numero, 2.0, '1')->assertCreated();
        $this->cobrar($a, 50);
        $this->debito($a, 'sri', 10)->assertCreated();
        // Cliente normal: contado y otra con nota que deja saldo a favor
        $this->facturar($this->cliente, 40, 0, 'efectivo');
        $c = $this->facturar($this->cliente, 60, 0);
        $this->cobrar($c, 58);
        $this->nota($c, 'interna', 10, 0)->assertCreated();        // 2 bajan y 8 quedan a favor
        // Relacionado: crédito con cobro
        $d = $this->facturar($this->clienteRel, 80, 0);
        $this->cobrar($d, 30);
        // Anulada: no debe contar
        $e = $this->facturar($this->clienteRel, 500, 0);
        $this->postJson("/api/invoices/{$e->id}/anular")->assertOk();
        // Cliente y proveedor a la vez, con cruce
        $f = $this->facturar($this->ambos, 100, 0);
        $compra = $this->comprar($this->ambos, 70, 0);
        $this->postJson("/api/receivables/{$f->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 25, 'documento_cruce' => $compra->id]],
        ])->assertOk();

        $this->assertConsistente();
        $r = $this->resumen()->json();
        $this->assertCount(3, $r['clientes']);
        $this->assertEquals(8.0, $r['totales']['saldo_favor_nc']);
        $this->assertEquals(round($r['totales']['saldo'] - 8.0, 2), $r['totales']['saldo_neto']);
    }

    // ============================================================ helpers

    private function url(string $ruta, array $q = []): string
    {
        return '/api/estado-cuenta/'.$ruta.'?'.http_build_query(['company_id' => $this->company->id] + $q);
    }

    private function resumen(array $q = []): TestResponse
    {
        return $this->getJson($this->url('clientes', $q));
    }

    private function detalle(Contact $c, array $q = []): TestResponse
    {
        return $this->getJson($this->url('clientes/'.$c->id, $q));
    }

    private function resumenProveedores(array $q = []): TestResponse
    {
        return $this->getJson($this->url('proveedores', $q));
    }

    private function detalleProveedor(Contact $c, array $q = []): TestResponse
    {
        return $this->getJson($this->url('proveedores/'.$c->id, $q));
    }

    /** Factura de un servicio: $base + IVA $tarifa %; a crédito o con otra forma de pago (al contado). */
    private function facturar(Contact $cliente, float $base, float $tarifa, string $formaPago = 'credito'): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => $formaPago,
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Compra de un servicio: $base + IVA $tarifa %. */
    private function comprar(Contact $proveedor, float $base, float $tarifa = 15, ?string $fecha = null): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => $fecha ?? now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function nota(Invoice $factura, string $tipo, float $valor, float $tarifa = 15): TestResponse
    {
        return $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $factura->contact_id, 'invoice_id' => $factura->id,
            'tipo' => $tipo, 'motivo' => 'Ajuste de prueba',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $valor, 'tarifa' => $tarifa]],
        ]);
    }

    private function debito(Invoice $factura, string $tipo, float $valor): TestResponse
    {
        return $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => $tipo, 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => $valor, 'tarifa' => 0]],
        ]);
    }

    private function cobrar(Invoice $factura, float $valor): void
    {
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => $valor]]])->assertOk();
    }

    /** Retención que un cliente le hizo a la empresa (XML del SRI), ligada a la factura por su número. */
    private function importarRetencion(string $numeroFactura, float $valor, string $marca): TestResponse
    {
        $sustento = str_replace('-', '', $numeroFactura);
        $hoy = now()->format('dmY');
        $clave = $hoy.'07'.'1790011223001'.'2'.'001001'.str_pad($marca, 9, '0', STR_PAD_LEFT).'12345678'.'1'.'3';
        $v = number_format($valor, 2, '.', '');
        $fecha = now()->format('d/m/Y');
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<comprobanteRetencion id="comprobante" version="1.0.0">
  <infoTributaria>
    <ambiente>1</ambiente><tipoEmision>1</tipoEmision>
    <razonSocial>Cliente que retiene</razonSocial><ruc>1790011223001</ruc>
    <claveAcceso>$clave</claveAcceso><codDoc>07</codDoc>
    <estab>001</estab><ptoEmi>001</ptoEmi><secuencial>000000001</secuencial>
  </infoTributaria>
  <infoCompRetencion>
    <fechaEmision>$fecha</fechaEmision>
    <identificacionSujetoRetenido>1791234567001</identificacionSujetoRetenido>
  </infoCompRetencion>
  <impuestos>
    <impuesto>
      <codigo>1</codigo><codigoRetencion>303</codigoRetencion>
      <baseImponible>100.00</baseImponible><porcentajeRetener>1</porcentajeRetener>
      <valorRetenido>$v</valorRetenido>
      <codDocSustento>01</codDocSustento><numDocSustento>$sustento</numDocSustento>
    </impuesto>
  </impuestos>
</comprobanteRetencion>
XML;

        return $this->post('/api/withholdings/import', [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('retencion.xml', $xml),
        ], ['Accept' => 'application/json']);
    }

    /** Saldo del libro mayor de esas cuentas: (Debe − Haber) × signo. */
    private function saldoCuentas(array $codigos, int $signo): float
    {
        $lineas = JournalEntry::where('company_id', $this->company->id)->get()->flatMap->lines
            ->filter(fn ($l) => in_array($l->account->codigo, $codigos, true));

        return round($signo * ((float) $lineas->sum('debe') - (float) $lineas->sum('haber')), 2);
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    /**
     * La regla de fondo del estado de cuenta: lo que muestra es lo mismo que está guardado y asentado.
     *  - La suma de saldos por cliente = la suma de saldo_pendiente de sus facturas vigentes; igual por proveedor y compras.
     *  - El saldo de cada documento = el saldo_pendiente de ese documento.
     *  - El desglose (total + débito − crédito − retenciones − cobros − anticipos) suma ese saldo, sin "otros ajustes".
     *  - Los movimientos cierran en el saldo, y el libro mayor (cartera y cuentas por pagar) dice lo mismo.
     */
    private function assertConsistente(): void
    {
        $cid = $this->company->id;
        $facturas = fn () => Invoice::soloFacturas()->where('company_id', $cid)->where('estado', '!=', 'anulado');

        $resumen = $this->resumen()->assertOk()->json();
        $this->assertEquals(round((float) $facturas()->sum('saldo_pendiente'), 2), $resumen['totales']['saldo'], 'Σ saldos por cliente ≠ Σ saldo_pendiente de las facturas');
        $this->assertEquals(round(array_sum(array_column($resumen['clientes'], 'saldo')), 2), $resumen['totales']['saldo']);
        foreach ($resumen['clientes'] as $fila) {
            $esperado = round((float) $facturas()->where('contact_id', $fila['contact_id'])->sum('saldo_pendiente'), 2);
            $this->assertEquals($esperado, $fila['saldo'], 'Saldo del cliente '.$fila['cliente']);

            $d = $this->detalle(Contact::findOrFail($fila['contact_id']))->assertOk()->json();
            foreach ($d['documentos'] as $doc) {
                $this->assertEquals((float) Invoice::findOrFail($doc['id'])->saldo_pendiente, $doc['saldo'], 'Saldo de '.$doc['numero']);
                $this->assertEquals(0.0, $doc['ajustes'], 'El desglose de '.$doc['numero'].' no suma su saldo');
                $this->assertEquals($doc['saldo'], round($doc['total'] + $doc['notas_debito_total'] - $doc['notas_credito_total']
                    - $doc['retenciones_total'] - $doc['cobros_total'] - $doc['anticipos_total'], 2), 'Desglose de '.$doc['numero']);
            }
            $this->assertEquals($fila['saldo'], $d['resumen']['saldo']);
            if ($d['movimientos']) {
                $this->assertEquals($fila['saldo'], end($d['movimientos'])['saldo'], 'Los movimientos de '.$fila['cliente'].' no cierran en su saldo');
            }
        }
        // El libro: facturas pendientes − notas de crédito a favor = saldo de cuentas por cobrar
        $this->assertEquals($this->saldoCuentas(['1.1.03', '1.1.09'], 1), round($resumen['totales']['saldo'] - $resumen['totales']['saldo_favor_nc'], 2), 'Cuentas por cobrar del libro');

        $prov = $this->resumenProveedores()->assertOk()->json();
        $this->assertEquals(round((float) Purchase::where('company_id', $cid)->sum('saldo_pendiente'), 2), $prov['totales']['saldo'], 'Σ saldos por proveedor ≠ Σ saldo_pendiente de las compras');
        foreach ($prov['proveedores'] as $fila) {
            $esperado = round((float) Purchase::where('company_id', $cid)->where('contact_id', $fila['contact_id'])->sum('saldo_pendiente'), 2);
            $this->assertEquals($esperado, $fila['saldo'], 'Saldo del proveedor '.$fila['proveedor']);

            $d = $this->detalleProveedor(Contact::findOrFail($fila['contact_id']))->assertOk()->json();
            foreach ($d['documentos'] as $doc) {
                $this->assertEquals((float) Purchase::findOrFail($doc['id'])->saldo_pendiente, $doc['saldo'], 'Saldo de '.$doc['numero']);
                $this->assertEquals(0.0, $doc['ajustes'], 'El desglose de '.$doc['numero'].' no suma su saldo');
                $this->assertEquals($doc['saldo'], round($doc['total'] - $doc['retenciones_total'] - $doc['pagos_total'] - $doc['anticipos_total'], 2), 'Desglose de '.$doc['numero']);
            }
            if ($d['movimientos']) {
                $this->assertEquals($fila['saldo'], end($d['movimientos'])['saldo'], 'Los movimientos de '.$fila['proveedor'].' no cierran en su saldo');
            }
        }
        $this->assertEquals($this->saldoCuentas(['2.1.01', '2.1.11'], -1), $prov['totales']['saldo'], 'Cuentas por pagar del libro');
        $this->assertEquals($this->saldoCuentas(['1.1.10'], 1), $prov['totales']['anticipos_sin_aplicar'], 'Anticipos a proveedores del libro');

        $this->chequeoEstricto()->assertExitCode(0);
    }
}
