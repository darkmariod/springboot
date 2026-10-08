<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
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
 * T3.2a: la nota de crédito va ligada a la factura que corrige.
 *   - SRI e interna EXIGEN la factura (de la misma empresa y del mismo cliente); el total no pasa de lo que
 *     queda por acreditar a esa factura.
 *   - Al emitirse baja de inmediato el saldo de la factura; lo que sobre queda como saldo a favor de la nota.
 *   - Asiento: Debe Devoluciones (subtotal) + Debe IVA por pagar / Haber CxC (total).
 *   - Interna: sin SriDocument, sin secuencial de la empresa, con número propio NCI-xxxxxx.
 *   - Anular: contra-asiento, devuelve el saldo a la factura; una factura con notas vigentes no se anula.
 */
class NotaCreditoFacturaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $clienteRel;
    private Contact $proveedor;
    /** @var string[] tipos de comprobante que se mandaron al SRI */
    private array $emitidos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturnUsing(function ($doc, string $tipo) {
            $this->emitidos[] = $tipo;

            return new SriDocument;
        });

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Notas Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        $this->clienteRel = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002',
            'razon_social' => 'Cliente Relacionado', 'parte_relacionada' => true,
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor Test', 'es_cliente' => false, 'es_proveedor' => true,
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ la factura es obligatoria

    public function test_la_nota_exige_elegir_la_factura_en_las_dos_clases(): void
    {
        foreach (['interna', 'sri'] as $tipo) {
            $r = $this->postJson('/api/credit-notes', [
                'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
                'tipo' => $tipo, 'motivo' => 'Sin factura', 'importe_total' => 10,
            ])->assertStatus(422);
            $this->assertStringContainsString('Selecciona la factura que corriges', $r->json('errors.invoice_id.0'), "tipo $tipo");
        }
        $this->assertSame(0, CreditNote::count());
        $this->assertSame(0, JournalEntry::where('concepto', 'like', 'Nota de crédito%')->count());
    }

    public function test_la_factura_debe_ser_de_la_misma_empresa_y_del_mismo_cliente_y_no_estar_anulada(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000009', 'razon_social' => 'Ajeno']);
        $ajena = Invoice::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'numero' => '001-001-000000001', 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 0, 'importe_total' => 100, 'forma_pago' => 'credito', 'saldo_pendiente' => 100,
            'estado' => 'emitida', 'fecha_emision' => now()]);

        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $ajena->id,
            'tipo' => 'interna', 'motivo' => 'Factura de otra empresa', 'importe_total' => 10,
        ])->assertStatus(422);
        $this->assertStringContainsString('Selecciona la factura que corriges', $r->json('errors.invoice_id.0'));

        $factura = $this->facturar($this->cliente);
        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->clienteRel->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Otro cliente', 'importe_total' => 10,
        ])->assertStatus(422);
        $this->assertStringContainsString('otro cliente', $r->json('errors.contact_id.0'));

        $factura->update(['estado' => 'anulado']);
        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Anulada', 'importe_total' => 10,
        ])->assertStatus(422);
        $this->assertStringContainsString('anulada', $r->json('errors.invoice_id.0'));
        $this->assertSame(0, CreditNote::count());
    }

    // ------------------------------------------------------------ el ejemplo del consultor

    public function test_factura_100_nota_10_y_retencion_1_dejan_la_deuda_en_89(): void
    {
        $factura = $this->facturar($this->cliente, 100, 0);              // $100 sin IVA
        $this->assertEquals(100.0, (float) $factura->saldo_pendiente);

        $this->nota($factura, 'interna', 10, 0)->assertCreated();
        $this->assertEquals(90.0, (float) $factura->fresh()->saldo_pendiente);

        $this->importarRetencion($factura->numero, 1.0, '1')->assertCreated();
        $this->assertEquals(89.0, (float) $factura->fresh()->saldo_pendiente, 'El cliente debe 100 - 10 - 1');

        // El libro mayor dice lo mismo: CxC = 100 - 10 - 1
        $this->assertEquals(89.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ interna: asiento con IVA y numeración propia

    public function test_nota_interna_con_iva_baja_el_saldo_y_asienta_devolucion_iva_y_cxc(): void
    {
        $factura = $this->facturar($this->cliente);                      // 100 + IVA 15 = 115
        $secuencialAntes = (int) $this->company->fresh()->secuencial;
        $emitidosAntes = $this->emitidos;

        $r = $this->nota($factura, 'interna', 10)->assertCreated();     // 10 + IVA 1.50 = 11.50
        $nota = CreditNote::findOrFail($r->json('id'));

        $this->assertEquals(10.0, (float) $nota->total_sin_impuestos);
        $this->assertEquals(1.5, (float) $nota->total_impuesto);
        $this->assertEquals(11.5, (float) $nota->importe_total);
        $this->assertSame($factura->id, $nota->invoice_id);

        // Debe 4.1.02 (subtotal) + Debe 2.1.02 (IVA) / Haber CxC (total): el cliente deja de deber 11.50
        $this->assertAsiento($nota, [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.03', 0, 11.5]]);

        // Baja el saldo de la factura al instante y la nota queda sin saldo a favor
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $nota->saldo_disponible);
        $this->assertEquals(11.5, (float) $nota->aplicado_factura);
        $this->assertEquals(103.5, $this->saldoCxc());

        // Interna: ni documento del SRI ni secuencial de la empresa; número propio
        $this->assertSame($emitidosAntes, $this->emitidos);
        $this->assertSame(0, SriDocument::where('documentable_type', $nota->getMorphClass())->count());
        $this->assertSame($secuencialAntes, (int) $this->company->fresh()->secuencial);
        $this->assertSame('NCI-000001', $nota->numero);

        $segunda = $this->nota($factura, 'interna', 10)->assertCreated();
        $this->assertSame('NCI-000002', CreditNote::findOrFail($segunda->json('id'))->numero);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_nota_de_un_cliente_relacionado_acredita_cxc_relacionadas_con_su_iva(): void
    {
        $factura = $this->facturar($this->clienteRel);
        $r = $this->nota($factura, 'interna', 10, 15, $this->clienteRel)->assertCreated();

        $this->assertAsiento(CreditNote::findOrFail($r->json('id')), [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.09', 0, 11.5]]);
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ SRI: misma ruta de emisión

    public function test_nota_sri_se_guarda_ligada_y_solo_emite_al_sri_cuando_se_pide(): void
    {
        $factura = $this->facturar($this->cliente);
        $emitidosAntes = $this->emitidos;
        $secuencialAntes = (int) $this->company->fresh()->secuencial;

        $r = $this->nota($factura, 'sri', 10)->assertCreated();
        $nota = CreditNote::findOrFail($r->json('id'));
        $this->assertSame('sri', $nota->tipo);
        $this->assertStringStartsNotWith('NCI-', (string) $nota->numero);
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertAsiento($nota, [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.03', 0, 11.5]]);
        $this->assertSame($emitidosAntes, $this->emitidos, 'Guardar la nota no la manda al SRI');

        $this->postJson("/api/credit-notes/{$nota->id}/emit")->assertOk();
        $this->assertSame([...$emitidosAntes, 'notaCredito'], $this->emitidos);
        $this->assertSame($secuencialAntes + 1, (int) $this->company->fresh()->secuencial);

        // La interna jamás se emite
        $interna = $this->nota($factura, 'interna', 5)->assertCreated();
        $this->postJson('/api/credit-notes/'.$interna->json('id').'/emit')->assertStatus(422);
        $this->assertSame([...$emitidosAntes, 'notaCredito'], $this->emitidos);
    }

    // ------------------------------------------------------------ tope: lo que queda por acreditar

    public function test_el_total_no_supera_la_factura_menos_lo_ya_acreditado_por_otras_notas_vigentes(): void
    {
        $factura = $this->facturar($this->cliente);                      // 115
        $primera = $this->nota($factura, 'interna', 100, 0)->assertCreated();   // 100 acreditados
        $this->assertEquals(15.0, (float) $factura->fresh()->saldo_pendiente);

        // Quedan 15: 16 no cabe
        $r = $this->nota($factura, 'interna', 16, 0)->assertStatus(422);
        $this->assertStringContainsString('supera', $r->json('errors.importe_total.0'));
        $this->assertSame(1, CreditNote::count());

        $this->nota($factura, 'interna', 15, 0)->assertCreated();
        $this->assertEquals(0.0, (float) $factura->fresh()->saldo_pendiente);
        $this->nota($factura, 'interna', 0.01, 0)->assertStatus(422);

        // Anular la primera libera su valor
        $this->postJson('/api/credit-notes/'.$primera->json('id').'/anular')->assertOk();
        $this->nota($factura, 'interna', 100, 0)->assertCreated();
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ lo que sobra queda a favor

    public function test_si_la_nota_supera_el_saldo_lo_que_sobra_queda_a_favor_y_se_usa_una_sola_vez(): void
    {
        $factura = $this->facturar($this->cliente);                      // 115
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 105]]])->assertOk();
        $this->assertEquals(10.0, (float) $factura->fresh()->saldo_pendiente);

        // NC de 20 (sin IVA): la factura solo debe 10 -> baja a 0 y sobran 10 a favor del cliente
        $nota = CreditNote::findOrFail($this->nota($factura, 'interna', 20, 0)->assertCreated()->json('id'));
        $this->assertEquals(0.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(10.0, (float) $nota->saldo_disponible);
        $this->assertEquals(10.0, (float) $nota->aplicado_factura);
        // CxC = deuda de facturas - saldo a favor = 0 - 10
        $this->assertEquals(-10.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);

        // Misma factura: ya no debe nada, no se puede volver a restar
        $this->postJson("/api/credits/apply/{$factura->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertStatus(422);
        $this->assertEquals(0.0, (float) $factura->fresh()->saldo_pendiente);

        // Otra factura del mismo cliente: se usa el saldo a favor
        $otra = $this->facturar($this->cliente);
        $antes = JournalEntry::count();
        $this->postJson("/api/credits/apply/{$otra->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertOk();
        $this->assertEquals(105.0, (float) $otra->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $nota->fresh()->saldo_disponible);
        $this->assertSame($antes, JournalEntry::count(), 'Aplicar solo baja saldos: la CxC se acreditó al emitir la nota');
        $this->chequeoEstricto()->assertExitCode(0);

        // Ya no queda nada por usar
        $this->postJson("/api/credits/apply/{$otra->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 0.01])->assertStatus(422);
        $this->assertEquals(105.0, (float) $otra->fresh()->saldo_pendiente);
    }

    public function test_una_nota_no_se_aplica_a_facturas_de_otro_cliente(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 105]]])->assertOk();
        $nota = CreditNote::findOrFail($this->nota($factura, 'interna', 20, 0)->assertCreated()->json('id'));
        $ajena = $this->facturar($this->clienteRel);

        $this->postJson("/api/credits/apply/{$ajena->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertStatus(422);
        $this->assertEquals(115.0, (float) $ajena->fresh()->saldo_pendiente);
        $this->assertEquals(10.0, (float) $nota->fresh()->saldo_disponible);
    }

    // ------------------------------------------------------------ anular

    public function test_anular_la_nota_reversa_el_asiento_y_devuelve_el_saldo_a_la_factura(): void
    {
        $factura = $this->facturar($this->cliente);
        $nota = CreditNote::findOrFail($this->nota($factura, 'interna', 10)->assertCreated()->json('id'));
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente);

        $this->postJson("/api/credit-notes/{$nota->id}/anular")->assertOk();

        $nota = $nota->fresh();
        $this->assertSame('anulado', $nota->tipo);
        $this->assertEquals(0.0, (float) $nota->saldo_disponible);
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);

        // Contra-asiento: el original queda y el reverso lo cancela línea por línea
        $asientos = JournalEntry::where('origen_type', $nota->getMorphClass())->where('origen_id', $nota->id)->orderBy('id')->get();
        $this->assertCount(2, $asientos);
        $this->assertLineas($asientos[0], [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.03', 0, 11.5]]);
        $this->assertLineas($asientos[1], [['4.1.02', 0, 10], ['2.1.02', 0, 1.5], ['1.1.03', 11.5, 0]]);
        $this->assertEquals(115.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);

        // Anular dos veces no hace nada más
        $this->postJson("/api/credit-notes/{$nota->id}/anular")->assertStatus(422);
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertCount(2, JournalEntry::where('origen_type', $nota->getMorphClass())->where('origen_id', $nota->id)->get());
    }

    public function test_anular_una_nota_con_saldo_a_favor_ya_usado_devuelve_todo_y_cuadra(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 105]]])->assertOk();
        $nota = CreditNote::findOrFail($this->nota($factura, 'interna', 20, 0)->assertCreated()->json('id'));
        $otra = $this->facturar($this->cliente);
        $this->postJson("/api/credits/apply/{$otra->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertOk();
        $this->assertEquals(105.0, (float) $otra->fresh()->saldo_pendiente);

        $this->postJson("/api/credit-notes/{$nota->id}/anular")->assertOk();

        // Cada factura vuelve a deber lo que la nota le había quitado
        $this->assertEquals(10.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(115.0, (float) $otra->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $nota->fresh()->saldo_disponible);
        $this->assertEquals(125.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_anular_la_nota_reversa_tambien_la_reclasificacion_de_cuentas_de_su_saldo_a_favor(): void
    {
        $normal = $this->facturar($this->cliente);
        $this->postJson("/api/receivables/{$normal->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 105]]])->assertOk();
        $nota = CreditNote::findOrFail($this->nota($normal, 'interna', 20, 0)->assertCreated()->json('id'));    // 10 a favor, en 1.1.03
        $this->cliente->update(['parte_relacionada' => true]);
        $relacionada = $this->facturar($this->cliente);                                                           // nace en 1.1.09
        $this->postJson("/api/credits/apply/{$relacionada->id}", ['tipo' => 'nota', 'id' => $nota->id, 'monto' => 10])->assertOk();
        $reclasificacion = JournalEntry::where('concepto', 'like', 'Aplicación de nota de crédito%')->firstOrFail();
        $this->assertLineas($reclasificacion, [['1.1.03', 10, 0], ['1.1.09', 0, 10]]);

        $this->postJson("/api/credit-notes/{$nota->id}/anular")->assertOk();

        // La reclasificación también se reversa: cada cuenta vuelve a su saldo real
        $reverso = JournalEntry::where('concepto', 'like', 'Reversión por anulación nota de crédito%')->orderBy('id')->get();
        $this->assertCount(2, $reverso);
        $this->assertLineas($reverso[1], [['1.1.03', 0, 10], ['1.1.09', 10, 0]]);
        $this->assertEquals(10.0, (float) $normal->fresh()->saldo_pendiente);
        $this->assertEquals(115.0, (float) $relacionada->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_anular_la_nota_con_devolucion_reversa_stock_y_costo_con_su_contra_asiento(): void
    {
        $producto = $this->comprarBien('BIEN-1', 10, 5);
        $factura = $this->facturarBien('BIEN-1', 2);                     // 2 x 10 + IVA = 23

        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'sri', 'motivo' => 'Devolución',
            'items' => [['codigo_principal' => 'BIEN-1', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();
        $nota = CreditNote::findOrFail($r->json('id'));
        $this->assertEquals(9.0, (float) $producto->fresh()->stock);
        $this->assertAsiento($nota, [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.03', 0, 11.5], ['1.1.05', 5, 0], ['5.1.04', 0, 5]]);

        $this->postJson("/api/credit-notes/{$nota->id}/anular")->assertOk();
        $this->assertEquals(8.0, (float) $producto->fresh()->stock);
        $reverso = JournalEntry::where('origen_type', $nota->getMorphClass())->where('origen_id', $nota->id)->orderBy('id')->get()[1];
        $this->assertLineas($reverso, [['4.1.02', 0, 10], ['2.1.02', 0, 1.5], ['1.1.03', 11.5, 0], ['1.1.05', 0, 5], ['5.1.04', 5, 0]]);
        $this->assertEquals(23.0, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_devuelve_stock_en_falso_es_una_nota_solo_de_valor(): void
    {
        $producto = $this->comprarBien('BIEN-1', 10, 5);
        $factura = $this->facturarBien('BIEN-1', 2);
        $this->assertEquals(8.0, (float) $producto->fresh()->stock);

        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Descuento comercial', 'devuelve_stock' => false,
            'items' => [['codigo_principal' => 'BIEN-1', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        $this->assertEquals(8.0, (float) $producto->fresh()->stock, 'Un descuento no devuelve mercadería');
        $this->assertAsiento(CreditNote::findOrFail($r->json('id')), [['4.1.02', 10, 0], ['2.1.02', 1.5, 0], ['1.1.03', 0, 11.5]]);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ factura con notas vigentes

    public function test_una_factura_con_notas_vigentes_no_se_anula_hasta_anular_las_notas(): void
    {
        $factura = $this->facturar($this->cliente);
        $nota = $this->nota($factura, 'interna', 10)->assertCreated();

        $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertStatus(422);
        $this->assertStringContainsString('notas', $r->json('message'));
        $this->assertSame('emitida', $factura->fresh()->estado);

        $this->postJson('/api/credit-notes/'.$nota->json('id').'/anular')->assertOk();
        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ listado

    public function test_el_listado_marca_las_internas_y_trae_la_factura_y_el_estado_sri(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->nota($factura, 'interna', 10)->assertCreated();
        $sri = $this->nota($factura, 'sri', 5)->assertCreated();
        $this->postJson('/api/credit-notes/'.$sri->json('id').'/anular')->assertOk();

        $lista = collect($this->getJson('/api/credit-notes?company_id='.$this->company->id)->assertOk()->json());
        $this->assertCount(2, $lista);
        $interna = $lista->firstWhere('tipo', 'interna');
        $this->assertSame('NCI-000001', $interna['numero']);
        $this->assertSame($factura->numero, $interna['invoice']['numero']);
        $this->assertTrue($interna['interna']);
        $anulada = $lista->firstWhere('tipo', 'anulado');
        $this->assertFalse($anulada['interna']);
    }

    public function test_las_facturas_se_piden_por_cliente_y_la_cartera_no_muestra_las_anuladas(): void
    {
        $a = $this->facturar($this->cliente);
        $this->facturar($this->clienteRel);
        $anulada = $this->facturar($this->cliente);
        $this->postJson("/api/invoices/{$anulada->id}/anular")->assertOk();

        $deCliente = collect($this->getJson('/api/invoices?company_id='.$this->company->id.'&contact_id='.$this->cliente->id)->assertOk()->json());
        $this->assertEqualsCanonicalizing([$a->id, $anulada->id], $deCliente->pluck('id')->all());

        $cartera = collect($this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk()->json('cartera'));
        $this->assertNotContains($anulada->id, $cartera->pluck('id')->all());
        $this->assertCount(2, $cartera);
    }

    // ------------------------------------------------------------ helpers

    /** Factura a crédito de un servicio: $base + IVA (por defecto $100 + 15 = $115). */
    private function facturar(Contact $cliente, float $base = 100, float $tarifa = 15): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Compra de un bien (con su asiento): deja el producto con ese stock y costo. */
    private function comprarBien(string $codigo, float $cantidad, float $costo): Product
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => $codigo, 'descripcion' => 'Bien', 'tipo' => 'bien']);
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000001',
            'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => $codigo, 'descripcion' => 'Bien', 'cantidad' => $cantidad, 'precio_unitario' => $costo, 'tarifa' => 15]],
        ])->assertCreated();

        return Product::where('company_id', $this->company->id)->where('codigo', $codigo)->firstOrFail();
    }

    private function facturarBien(string $codigo, float $cantidad): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => $codigo, 'descripcion' => 'Bien', 'cantidad' => $cantidad, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Nota de $valor (base) con IVA $tarifa sobre un servicio; sin items no se usa: aquí siempre va por líneas. */
    private function nota(Invoice $factura, string $tipo, float $valor, float $tarifa = 15, ?Contact $cliente = null)
    {
        return $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => ($cliente ?? $this->cliente)->id, 'invoice_id' => $factura->id,
            'tipo' => $tipo, 'motivo' => 'Ajuste de prueba',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $valor, 'tarifa' => $tarifa]],
        ]);
    }

    private function importarRetencion(string $numeroFactura, float $valor, string $marca)
    {
        $sustento = str_replace('-', '', $numeroFactura);
        $clave = '15092026'.'07'.'1790011223001'.'2'.'001001'.str_pad($marca, 9, '0', STR_PAD_LEFT).'12345678'.'1'.'3';
        $v = number_format($valor, 2, '.', '');
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
    <fechaEmision>15/09/2026</fechaEmision>
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

    /** Saldo del libro mayor de las cuentas por cobrar (1.1.03 + 1.1.09): Debe - Haber. */
    private function saldoCxc(): float
    {
        $lineas = JournalEntry::where('company_id', $this->company->id)->get()->flatMap->lines
            ->filter(fn ($l) => in_array($l->account->codigo, ['1.1.03', '1.1.09'], true));

        return round((float) $lineas->sum('debe') - (float) $lineas->sum('haber'), 2);
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    /** El asiento que genera el propio documento: exactamente uno, con esas líneas. */
    private function assertAsiento($origen, array $esperado): void
    {
        $asientos = JournalEntry::where('origen_type', $origen->getMorphClass())->where('origen_id', $origen->getKey())->get();
        $this->assertCount(1, $asientos, 'Se esperaba exactamente un asiento para '.class_basename($origen).' #'.$origen->getKey());
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
