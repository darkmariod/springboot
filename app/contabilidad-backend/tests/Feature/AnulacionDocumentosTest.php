<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.4: anulación clara.
 *   (a) Anular NUNCA borra: el documento y su número se conservan (la secuencia no se reutiliza) y la lista lo marca anulado.
 *   (b) Si el SRI ya lo había autorizado, la respuesta trae `requiere_anulacion_sri` = true y un aviso en español: está anulado
 *       en el sistema, pero también hay que anularlo en el portal SRI en línea. Si no estaba autorizado, el aviso dice que
 *       no hace falta ninguna acción en el SRI.
 *   (c) Anular dos veces es 422.
 */
class AnulacionDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // El SRI no se llama: cada prueba arma el estado del comprobante a mano
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Anulación Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function facturar(float $base = 100): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** El comprobante electrónico del documento, en el estado que el SRI le dejó. */
    private function conSri(Model $documento, string $estado): SriDocument
    {
        return SriDocument::create([
            'company_id' => $this->company->id, 'documentable_type' => $documento->getMorphClass(), 'documentable_id' => $documento->getKey(),
            'tipo_comprobante' => 'factura', 'clave_acceso' => str_pad((string) (7000000000 + $documento->getKey()), 49, '0', STR_PAD_LEFT),
            'xml' => '<x/>', 'estado' => $estado, 'ambiente' => 1, 'fecha_emision' => now(),
        ]);
    }

    private function notaCredito(Invoice $factura, string $tipo): CreditNote
    {
        $r = $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => $tipo, 'motivo' => 'Ajuste de prueba',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        return CreditNote::findOrFail($r->json('id'));
    }

    private function notaDebito(Invoice $factura, string $tipo): Invoice
    {
        $r = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => $tipo, 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => 20, 'tarifa' => 0]],
        ])->assertCreated();

        return Invoice::findOrFail($r->json('nota_debito.id'));
    }

    // ------------------------------------------------------------ (a) el documento y su secuencia se conservan

    public function test_anular_una_factura_la_marca_anulada_sin_borrar_nada_y_la_secuencia_no_se_reutiliza(): void
    {
        $factura = $this->facturar();
        $doc = $this->conSri($factura, 'generado');
        $numero = $factura->numero;
        $secuenciaAntes = (int) $this->company->fresh()->secuencial;
        $asientosAntes = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->count();

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk()->assertJson(['ok' => true]);

        $factura = Invoice::find($factura->id);
        $this->assertNotNull($factura, 'La factura anulada no se borra');
        $this->assertSame('anulado', $factura->estado);
        $this->assertSame($numero, $factura->numero, 'Conserva su número');
        $this->assertNotNull(SriDocument::find($doc->id), 'Su comprobante electrónico tampoco se borra');
        $this->assertSame($secuenciaAntes, (int) $this->company->fresh()->secuencial, 'Anular no devuelve el secuencial');
        $this->assertSame($asientosAntes + 1, JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->count(),
            'El asiento original queda y se suma su contra-asiento');

        // La siguiente factura sigue la secuencia: el número anulado no se vuelve a usar
        $siguiente = $this->facturar();
        $this->assertNotSame($numero, $siguiente->numero);
        $this->assertSame((int) substr($numero, -9) + 1, (int) substr($siguiente->numero, -9));
        $this->assertSame(2, Invoice::where('company_id', $this->company->id)->count());
    }

    public function test_los_listados_muestran_lo_anulado_y_el_estado_del_sri_de_cada_documento(): void
    {
        $factura = $this->facturar();
        $this->conSri($factura, 'AUTORIZADO');
        $otra = $this->facturar();
        $this->postJson("/api/invoices/{$otra->id}/anular")->assertOk();

        $facturas = collect($this->getJson('/api/invoices?company_id='.$this->company->id)->assertOk()->json())->keyBy('id');
        $this->assertSame('anulado', $facturas[$otra->id]['estado']);
        $this->assertSame('emitida', $facturas[$factura->id]['estado']);
        $this->assertSame('AUTORIZADO', $facturas[$factura->id]['sri_document']['estado'], 'La lista trae el estado del SRI para pedir la confirmación');

        $nc = $this->notaCredito($factura, 'sri');
        $this->conSri($nc, 'AUTORIZADO');
        $this->postJson("/api/credit-notes/{$nc->id}/anular")->assertOk();
        $nota = collect($this->getJson('/api/credit-notes?company_id='.$this->company->id)->assertOk()->json())->firstWhere('id', $nc->id);
        $this->assertSame('anulado', $nota['tipo']);
        $this->assertSame('AUTORIZADO', $nota['sri_document']['estado']);

        $nd = $this->notaDebito($factura, 'sri');
        $this->conSri($nd, 'AUTORIZADO');
        $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertOk();
        $debito = collect($this->getJson('/api/notas-debito?company_id='.$this->company->id)->assertOk()->json())->firstWhere('id', $nd->id);
        $this->assertSame('anulado', $debito['estado']);
        $this->assertSame('AUTORIZADO', $debito['sri_document']['estado']);
    }

    // ------------------------------------------------------------ (b) el aviso del portal del SRI

    public function test_una_factura_autorizada_se_anula_en_el_sistema_y_avisa_que_hay_que_anularla_en_el_portal_del_sri(): void
    {
        $factura = $this->facturar();
        $this->conSri($factura, 'AUTORIZADO');

        $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        $this->assertTrue($r->json('requiere_anulacion_sri'));
        $this->assertSame('autorizado', $r->json('sri_estado'));
        $aviso = $r->json('aviso_sri');
        $this->assertStringContainsString($factura->numero, $aviso);
        $this->assertStringContainsString('anulada en el sistema', $aviso);
        $this->assertStringContainsString('portal SRI en línea', $aviso);
        $this->assertSame('anulado', $factura->fresh()->estado);
        $this->assertSame('AUTORIZADO', $factura->sriDocument()->first()->estado, 'No se toca el comprobante del SRI: solo el SRI lo anula');
    }

    public function test_una_factura_que_no_fue_autorizada_dice_que_no_hace_falta_ninguna_accion_en_el_sri(): void
    {
        $sinSri = $this->facturar();
        $generada = $this->facturar();
        $this->conSri($generada, 'generado');
        $firmada = $this->facturar();
        $this->conSri($firmada, 'firmado');
        $rechazada = $this->facturar();
        $this->conSri($rechazada, 'NO AUTORIZADO');

        foreach ([$sinSri, $generada, $firmada, $rechazada] as $f) {
            $r = $this->postJson("/api/invoices/{$f->id}/anular")->assertOk();
            $this->assertFalse($r->json('requiere_anulacion_sri'), $f->numero);
            $this->assertStringContainsString('no hace falta ninguna acción en el SRI', $r->json('aviso_sri'), $f->numero);
            $this->assertStringNotContainsString('portal SRI en línea', $r->json('aviso_sri'), $f->numero);
        }
    }

    public function test_una_factura_enviada_que_el_sri_aun_no_autoriza_avisa_del_portal_por_si_la_autoriza_despues(): void
    {
        $factura = $this->facturar();
        $this->conSri($factura, 'enviado');

        $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        $this->assertFalse($r->json('requiere_anulacion_sri'), 'Todavía no está autorizada');
        $this->assertSame('enviado', $r->json('sri_estado'));
        $this->assertStringContainsString('todavía no responde', $r->json('aviso_sri'));
        $this->assertStringContainsString('portal SRI en línea', $r->json('aviso_sri'));
    }

    public function test_una_nota_de_credito_autorizada_requiere_anular_tambien_en_el_sri(): void
    {
        $factura = $this->facturar();
        $nc = $this->notaCredito($factura, 'sri');
        $this->conSri($nc, 'AUTORIZADO');

        $r = $this->postJson("/api/credit-notes/{$nc->id}/anular")->assertOk();

        $this->assertTrue($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('La nota de crédito', $r->json('aviso_sri'));
        $this->assertStringContainsString('portal SRI en línea', $r->json('aviso_sri'));
        $this->assertNotNull(CreditNote::find($nc->id), 'La nota anulada no se borra');
        $this->assertSame('anulado', $nc->fresh()->tipo);
    }

    public function test_una_nota_de_credito_sin_autorizar_o_interna_no_requiere_nada_en_el_sri(): void
    {
        $factura = $this->facturar();
        $sriSinEmitir = $this->notaCredito($factura, 'sri');
        $interna = $this->notaCredito($factura, 'interna');

        $r = $this->postJson("/api/credit-notes/{$sriSinEmitir->id}/anular")->assertOk();
        $this->assertFalse($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('no hace falta ninguna acción en el SRI', $r->json('aviso_sri'));

        $r = $this->postJson("/api/credit-notes/{$interna->id}/anular")->assertOk();
        $this->assertFalse($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('no hace falta ninguna acción en el SRI', $r->json('aviso_sri'));
        $this->assertStringContainsString('interno', $r->json('aviso_sri'));
    }

    public function test_una_nota_de_debito_autorizada_requiere_anular_tambien_en_el_sri_y_la_interna_no(): void
    {
        $factura = $this->facturar();
        $sri = $this->notaDebito($factura, 'sri');
        $this->conSri($sri, 'AUTORIZADO');
        $interna = $this->notaDebito($factura, 'interna');

        $r = $this->postJson("/api/notas-debito/{$sri->id}/anular")->assertOk();
        $this->assertTrue($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('La nota de débito', $r->json('aviso_sri'));
        $this->assertStringContainsString('portal SRI en línea', $r->json('aviso_sri'));
        $this->assertNotNull(Invoice::find($sri->id), 'La nota anulada no se borra');
        $this->assertSame('anulado', $sri->fresh()->estado);

        $r = $this->postJson("/api/notas-debito/{$interna->id}/anular")->assertOk();
        $this->assertFalse($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('no hace falta ninguna acción en el SRI', $r->json('aviso_sri'));
    }

    // ------------------------------------------------------------ (c) anular dos veces

    public function test_anular_dos_veces_da_422_y_no_repite_el_contra_asiento_ni_la_auditoria(): void
    {
        $factura = $this->facturar();
        $nc = $this->notaCredito($this->facturar(), 'interna');
        $nd = $this->notaDebito($this->facturar(), 'interna');

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();
        $this->postJson("/api/credit-notes/{$nc->id}/anular")->assertOk();
        $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertOk();
        $asientos = JournalEntry::count();
        $auditorias = AuditLog::where('accion', 'anulo')->count();

        $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertStatus(422);
        $this->assertStringContainsString('ya está anulada', $r->json('message'));
        $r = $this->postJson("/api/credit-notes/{$nc->id}/anular")->assertStatus(422);
        $this->assertStringContainsString('ya está anulada', $r->json('message'));
        $r = $this->postJson("/api/notas-debito/{$nd->id}/anular")->assertStatus(422);
        $this->assertStringContainsString('ya está anulada', $r->json('message'));

        $this->assertSame($asientos, JournalEntry::count(), 'La segunda vez no crea otro contra-asiento');
        $this->assertSame($auditorias, AuditLog::where('accion', 'anulo')->count(), 'Un intento rechazado no queda como una anulación en la auditoría');
    }
}
