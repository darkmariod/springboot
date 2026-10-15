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
 * T3.8 (mitad del menú): las notas de crédito y de débito INTERNAS solo regulan saldos y no pasan por el SRI,
 * así que una empresa sin facturación electrónica (plan Básico: tiene 'cartera', no tiene 'facturacion_sri')
 * las crea, las lista y las anula. Una nota del SRI, en cambio, exige 'facturacion_sri' (402).
 */
class NotasInternasSinSriTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Invoice $factura;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Sin SRI SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'basico', 'secuencial' => 5,
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        $this->factura = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => '001-001-000000001', 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'forma_pago' => 'credito',
            'saldo_pendiente' => 115, 'estado' => 'emitida', 'fecha_emision' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_el_plan_basico_tiene_cartera_y_no_tiene_facturacion_sri(): void
    {
        $this->assertContains('cartera', config('planes.basico.features'));
        $this->assertNotContains('facturacion_sri', config('planes.basico.features'));
        $this->assertTrue($this->company->tieneFeature('cartera'));
        $this->assertFalse($this->company->tieneFeature('facturacion_sri'));
    }

    // ------------------------------------------------------------ nota de débito

    public function test_sin_sri_pero_con_cartera_se_crea_una_nota_de_debito_interna(): void
    {
        $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'interna',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => 10]],
        ])->assertStatus(201)
            ->assertJsonPath('nota_debito.tipo_comprobante', 'nota_debito_interna')
            ->assertJsonPath('nota_debito.numero', 'NDI-000001');

        $this->assertEquals(125.0, (float) $this->factura->fresh()->saldo_pendiente);
        $this->assertSame(0, SriDocument::count(), 'Una nota interna no crea documento del SRI');
        $this->assertSame(5, (int) $this->company->fresh()->secuencial, 'Una nota interna no gasta el secuencial de la empresa');
    }

    public function test_sin_sri_no_se_crea_una_nota_de_debito_del_sri_ni_se_emite(): void
    {
        foreach ([['tipo' => 'sri'], []] as $extra) {   // sin "tipo" la nota es del SRI
            $this->postJson('/api/notas-debito', $extra + [
                'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'forma_pago' => '01',
                'motivos' => [['razon' => 'Interés por mora', 'valor' => 10]],
            ])->assertStatus(402);
        }
        $this->assertSame(0, Invoice::where('tipo_comprobante', 'like', 'nota_debito%')->count());
        $this->assertEquals(115.0, (float) $this->factura->fresh()->saldo_pendiente);
        $this->assertSame(5, (int) $this->company->fresh()->secuencial);

        // Emitir una nota al SRI sigue exigiendo la función
        $interna = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'interna',
            'motivos' => [['razon' => 'Interés', 'valor' => 5]],
        ])->assertStatus(201)->json('nota_debito.id');
        $this->postJson("/api/notas-debito/{$interna}/emit", ['company_id' => $this->company->id])->assertStatus(402);
    }

    public function test_sin_sri_se_lista_y_se_anula_la_nota_de_debito_interna(): void
    {
        $id = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'interna',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => 10]],
        ])->assertStatus(201)->json('nota_debito.id');

        $this->getJson('/api/notas-debito?company_id='.$this->company->id)->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', $id)->assertJsonPath('0.interna', true);

        $this->postJson("/api/notas-debito/{$id}/anular", ['company_id' => $this->company->id])->assertOk();
        $this->assertSame('anulado', Invoice::findOrFail($id)->estado);
        $this->assertEquals(115.0, (float) $this->factura->fresh()->saldo_pendiente);
    }

    // ------------------------------------------------------------ nota de crédito

    public function test_sin_sri_pero_con_cartera_se_crea_una_nota_de_credito_interna_y_la_del_sri_da_402(): void
    {
        $base = [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $this->factura->id,
            'motivo' => 'Descuento posterior', 'importe_total' => 10,
        ];

        $this->postJson('/api/credit-notes', $base + ['tipo' => 'sri'])->assertStatus(402);
        $this->assertSame(0, CreditNote::count());

        $this->postJson('/api/credit-notes', $base + ['tipo' => 'interna'])->assertStatus(201);
        $this->assertSame(1, CreditNote::count());
        $this->assertSame('interna', CreditNote::firstOrFail()->tipo);
        $this->assertEquals(105.0, (float) $this->factura->fresh()->saldo_pendiente);

        $this->getJson('/api/credit-notes?company_id='.$this->company->id)->assertOk()->assertJsonCount(1);
    }

    public function test_con_el_plan_negocio_la_nota_del_sri_sigue_funcionando(): void
    {
        $this->company->update(['plan' => 'negocio']);

        $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $this->factura->id, 'tipo' => 'sri', 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => 10]],
        ])->assertStatus(201)->assertJsonPath('nota_debito.tipo_comprobante', 'nota_debito');
    }
}
