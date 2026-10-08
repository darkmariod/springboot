<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.2b: la nota de débito (interés, mora, cargo extra) va ligada a una factura, SUBE su saldo y deja asiento:
 *   Debe CxC (la de la factura) / Haber Otros ingresos por notas de débito (4.1.03) + Haber IVA por pagar.
 * Dos clases: SRI (se emite al SRI como siempre) e interna (sin SriDocument, sin secuencial, con número NDI-xxxxxx).
 */
class NotaDebitoAsientoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $clienteRel;
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
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Debito Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        $this->clienteRel = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002',
            'razon_social' => 'Cliente Relacionado', 'parte_relacionada' => true,
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ catálogo

    public function test_la_cuenta_de_ingresos_por_notas_de_debito_es_nueva_y_no_rompe_la_forma_de_pago_nota_de_debito(): void
    {
        $this->assertSame(['codigo' => '4.1.03', 'nombre' => 'Otros ingresos por notas de débito', 'tipo' => 'ingreso'], config('cuentas.cuentas.ingresos_notas_debito'));

        $codigos = array_column(config('cuentas.cuentas'), 'codigo');
        $this->assertSame(array_values(array_unique($codigos)), $codigos, 'Hay códigos repetidos');

        // La forma de pago "Nota de Débito" sigue usando su cuenta de pasivo 2.1.08
        $this->assertSame('2.1.08', Cuentas::codigo('notas_debito'));
        $this->assertSame('notas_debito', config('formas_pago.nota_debito.cuenta'));

        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $this->assertSame('Otros ingresos por notas de débito', Account::where('company_id', $demo->id)->where('codigo', '4.1.03')->value('nombre'));
    }

    // ------------------------------------------------------------ la factura es obligatoria

    public function test_la_nota_de_debito_exige_la_factura_de_la_misma_empresa(): void
    {
        foreach (['interna', 'sri'] as $tipo) {
            $r = $this->postJson('/api/notas-debito', [
                'company_id' => $this->company->id, 'tipo' => $tipo, 'forma_pago' => '01',
                'motivos' => [['razon' => 'Interés', 'valor' => 5]],
            ])->assertStatus(422);
            $this->assertStringContainsString('Selecciona la factura', $r->json('errors.invoice_id.0'), "tipo $tipo");
        }

        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000009', 'razon_social' => 'Ajeno']);
        $ajena = Invoice::create(['company_id' => $otra->id, 'contact_id' => $ajeno->id, 'numero' => '001-001-000000001', 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 0, 'importe_total' => 100, 'forma_pago' => 'credito', 'saldo_pendiente' => 100,
            'estado' => 'emitida', 'fecha_emision' => now()]);
        $r = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $ajena->id, 'tipo' => 'interna',
            'motivos' => [['razon' => 'Interés', 'valor' => 5]],
        ])->assertStatus(422);
        $this->assertStringContainsString('Selecciona la factura', $r->json('errors.invoice_id.0'));
        $this->assertEquals(100.0, (float) $ajena->fresh()->saldo_pendiente);
        $this->assertSame(0, Invoice::where('tipo_comprobante', 'like', 'nota_debito%')->count());
    }

    // ------------------------------------------------------------ interna

    public function test_nota_de_debito_interna_sube_el_saldo_de_la_factura_y_asienta_cxc_contra_ingresos(): void
    {
        $factura = $this->facturar($this->cliente);                      // 115
        $secuencialAntes = (int) $this->company->fresh()->secuencial;
        $emitidosAntes = $this->emitidos;

        $r = $this->nd($factura, 'interna', [['razon' => 'Interés por mora', 'valor' => 10]])->assertCreated();
        $nd = Invoice::findOrFail($r->json('nota_debito.id'));

        $this->assertSame('nota_debito_interna', $nd->tipo_comprobante);
        $this->assertSame('NDI-000001', $nd->numero);
        $this->assertSame($factura->numero, $nd->numero_referencia);
        $this->assertEquals(10.0, (float) $nd->importe_total);
        $this->assertEquals(0.0, (float) $nd->saldo_pendiente, 'La deuda vive en la factura, no en la nota');

        // La factura debe ahora 115 + 10 y el libro mayor lo dice igual
        $this->assertEquals(125.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertAsiento($nd, [['1.1.03', 10, 0], ['4.1.03', 0, 10]]);
        $this->assertEquals(125.0, $this->saldoCxc());

        // Interna: ni SriDocument ni secuencial
        $this->assertSame($emitidosAntes, $this->emitidos);
        $this->assertSame($secuencialAntes, (int) $this->company->fresh()->secuencial);
        $this->assertSame(0, SriDocument::where('documentable_type', $nd->getMorphClass())->count());

        $segunda = $this->nd($factura, 'interna', [['razon' => 'Gastos de cobranza', 'valor' => 2]])->assertCreated();
        $this->assertSame('NDI-000002', Invoice::findOrFail($segunda->json('nota_debito.id'))->numero);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_el_cargo_con_iva_acredita_ingreso_e_iva_por_pagar(): void
    {
        $factura = $this->facturar($this->cliente);
        $r = $this->nd($factura, 'interna', [['razon' => 'Cargo administrativo', 'valor' => 20, 'tarifa' => 15], ['razon' => 'Interés', 'valor' => 5]])->assertCreated();
        $nd = Invoice::findOrFail($r->json('nota_debito.id'));

        $this->assertEquals(25.0, (float) $nd->total_sin_impuestos);
        $this->assertEquals(3.0, (float) $nd->total_impuesto);
        $this->assertEquals(28.0, (float) $nd->importe_total);
        $this->assertAsiento($nd, [['1.1.03', 28, 0], ['4.1.03', 0, 25], ['2.1.02', 0, 3]]);
        $this->assertEquals(143.0, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_la_nota_de_debito_de_un_cliente_relacionado_debita_cxc_relacionadas(): void
    {
        $factura = $this->facturar($this->clienteRel);
        $r = $this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 10]])->assertCreated();

        $this->assertAsiento(Invoice::findOrFail($r->json('nota_debito.id')), [['1.1.09', 10, 0], ['4.1.03', 0, 10]]);
        $this->assertEquals(125.0, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    // ------------------------------------------------------------ SRI: misma ruta de emisión

    public function test_nota_de_debito_sri_deja_el_mismo_asiento_y_se_emite_al_sri_solo_la_sri(): void
    {
        $factura = $this->facturar($this->cliente);
        $emitidosAntes = $this->emitidos;

        $r = $this->nd($factura, 'sri', [['razon' => 'Interés por mora', 'valor' => 10]])->assertCreated();
        $nd = Invoice::findOrFail($r->json('nota_debito.id'));
        $this->assertSame('nota_debito', $nd->tipo_comprobante);
        $this->assertStringStartsNotWith('NDI-', (string) $nd->numero);
        $this->assertAsiento($nd, [['1.1.03', 10, 0], ['4.1.03', 0, 10]]);
        $this->assertEquals(125.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame($emitidosAntes, $this->emitidos, 'Guardar la nota no la manda al SRI');

        $this->postJson("/api/notas-debito/{$nd->id}/emit")->assertOk();
        $this->assertSame([...$emitidosAntes, 'notaDebito'], $this->emitidos);

        $interna = $this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 1]])->assertCreated();
        $this->postJson('/api/notas-debito/'.$interna->json('nota_debito.id').'/emit')->assertStatus(422);
        $this->assertSame([...$emitidosAntes, 'notaDebito'], $this->emitidos);

        // Una factura normal no es una nota de débito
        $this->postJson("/api/notas-debito/{$factura->id}/emit")->assertStatus(422);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_sin_tipo_la_nota_de_debito_es_sri_como_siempre(): void
    {
        $factura = $this->facturar($this->cliente);
        $r = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés', 'valor' => 10]],
        ])->assertCreated();

        $this->assertSame('nota_debito', Invoice::findOrFail($r->json('nota_debito.id'))->tipo_comprobante);
        $this->assertEquals(125.0, (float) $factura->fresh()->saldo_pendiente);
    }

    // ------------------------------------------------------------ cobro, combinación con NC y anulación

    public function test_el_cliente_paga_el_saldo_con_la_nota_de_debito_y_una_nota_de_credito_y_todo_cuadra(): void
    {
        $factura = $this->facturar($this->cliente);                      // 115
        $this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 10]])->assertCreated();   // 125
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Descuento',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();                                                                       // 125 - 11.50
        $this->assertEquals(113.5, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeoEstricto()->assertExitCode(0);

        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 113.5]]])->assertOk();
        $this->assertEquals(0.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_anular_la_nota_de_debito_reversa_el_asiento_y_baja_el_saldo(): void
    {
        $factura = $this->facturar($this->cliente);
        $nd = Invoice::findOrFail($this->nd($factura, 'interna', [['razon' => 'Cargo', 'valor' => 20, 'tarifa' => 15]])->assertCreated()->json('nota_debito.id'));
        $this->assertEquals(138.0, (float) $factura->fresh()->saldo_pendiente);

        $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertOk();

        $this->assertSame('anulado', $nd->fresh()->estado);
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
        $asientos = JournalEntry::where('origen_type', $nd->getMorphClass())->where('origen_id', $nd->id)->orderBy('id')->get();
        $this->assertCount(2, $asientos);
        $this->assertLineas($asientos[0], [['1.1.03', 23, 0], ['4.1.03', 0, 20], ['2.1.02', 0, 3]]);
        $this->assertLineas($asientos[1], [['1.1.03', 0, 23], ['4.1.03', 20, 0], ['2.1.02', 3, 0]]);
        $this->assertEquals(115.0, $this->saldoCxc());
        $this->chequeoEstricto()->assertExitCode(0);

        $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertStatus(422);
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);
    }

    public function test_no_se_anula_la_nota_de_debito_si_el_cliente_ya_pago_ese_valor(): void
    {
        $factura = $this->facturar($this->cliente);
        $nd = Invoice::findOrFail($this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 10]])->assertCreated()->json('nota_debito.id'));
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 120]]])->assertOk();
        $this->assertEquals(5.0, (float) $factura->fresh()->saldo_pendiente);

        $r = $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertStatus(422);
        $this->assertStringContainsString('cobr', $r->json('message'));
        $this->assertSame('emitida', $nd->fresh()->estado);
        $this->assertEquals(5.0, (float) $factura->fresh()->saldo_pendiente);
    }

    public function test_una_factura_con_notas_de_debito_vigentes_no_se_anula(): void
    {
        $factura = $this->facturar($this->cliente);
        $nd = $this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 10]])->assertCreated();

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertStatus(422);
        $this->assertSame('emitida', $factura->fresh()->estado);

        $this->postJson('/api/notas-debito/'.$nd->json('nota_debito.id').'/anular')->assertOk();
        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_el_chequeo_estricto_no_cuenta_las_notas_de_debito_anteriores_sin_asiento_pero_las_muestra(): void
    {
        $factura = $this->facturar($this->cliente);
        // Una nota de débito de antes de esta versión: sin factura ligada ni asiento, con su propio saldo
        Invoice::create(['company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => '001-001-000000777',
            'items' => [['razon' => 'Interés', 'valor' => 10]], 'total_sin_impuestos' => 10, 'total_impuesto' => 0, 'importe_total' => 10,
            'forma_pago' => 'efectivo', 'saldo_pendiente' => 10, 'estado' => 'emitida', 'fecha_emision' => now(),
            'tipo_comprobante' => 'nota_debito', 'numero_referencia' => $factura->numero]);

        // Hacia adelante: lo anterior no se reprocesa, se muestra como informativo y no hace fallar
        $this->chequeoEstricto()
            ->expectsOutputToContain('Notas de débito anteriores sin asiento')
            ->expectsOutputToContain('OK  Cuentas por cobrar')
            ->assertExitCode(0);

        // Las nuevas sí cuentan: una nota de débito con asiento mantiene la identidad
        $this->nd($factura, 'interna', [['razon' => 'Mora', 'valor' => 5]])->assertCreated();
        $this->chequeoEstricto()->expectsOutputToContain('OK  Cuentas por cobrar')->assertExitCode(0);
    }

    // ------------------------------------------------------------ listados

    public function test_el_listado_trae_sri_e_internas_marcadas_y_las_facturas_no_las_mezclan(): void
    {
        $factura = $this->facturar($this->cliente);
        $this->nd($factura, 'interna', [['razon' => 'Interés', 'valor' => 10]])->assertCreated();
        $this->nd($factura, 'sri', [['razon' => 'Mora', 'valor' => 5]])->assertCreated();

        $lista = collect($this->getJson('/api/notas-debito?company_id='.$this->company->id)->assertOk()->json());
        $this->assertCount(2, $lista);
        $this->assertTrue($lista->firstWhere('tipo_comprobante', 'nota_debito_interna')['interna']);
        $this->assertFalse($lista->firstWhere('tipo_comprobante', 'nota_debito')['interna']);

        // La pantalla de facturas y la cartera no las muestran como facturas
        $facturas = collect($this->getJson('/api/invoices?company_id='.$this->company->id)->assertOk()->json());
        $this->assertSame([$factura->id], $facturas->pluck('id')->all());
        $cartera = $this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk();
        $this->assertCount(1, $cartera->json('cartera'));
        $this->assertEquals(130.0, $cartera->json('total'));
    }

    // ------------------------------------------------------------ helpers

    /** Factura a crédito de un servicio: $100 + IVA 15 = $115. */
    private function facturar(Contact $cliente): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function nd(Invoice $factura, string $tipo, array $motivos)
    {
        return $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => $tipo,
            'forma_pago' => '01', 'motivos' => $motivos,
        ]);
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
