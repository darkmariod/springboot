<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\Withholding;
use App\Services\EnviarComprobanteCorreo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T10.2a: el envío AUTOMÁTICO. Cuando el comprobante pasa a AUTORIZADO (por la emisión, por "Autorizar todo", como sea) y la
 * empresa lo activó, el correo sale solo, una sola vez, con el RIDE y el XML autorizado. Se engancha UNA vez, en el modelo
 * SriDocument, al guardar el cambio de estado. Un fallo del correo jamás rompe ni deshace la autorización. Sin certificado el
 * comprobante queda "generado" y no sale nada.
 */
class CorreoComprobanteAutomaticoTest extends CorreoTestCase
{
    private function automatico(bool $on = true): void
    {
        $this->company->update(['enviar_comprobantes_automaticamente' => $on]);
    }

    private function facturar(): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 2, 'precio_unitario' => 50, 'tarifa' => 15]],
        ])->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------ cada comprobante, por su camino real de emisión

    public function test_la_factura_emitida_y_autorizada_sale_por_correo_sola_y_una_vez(): void
    {
        $this->conCertificado();
        $this->automatico();
        $this->sriFalso();

        $factura = $this->facturar();

        $sri = $factura->sriDocument()->firstOrFail();
        $this->assertSame('AUTORIZADO', $sri->estado);
        $this->assertCount(1, $this->transporte->messages(), 'Exactamente un correo');
        $correo = $this->correo();
        $this->assertSame('Factura '.$factura->numero.' de Distribuidora Andina SA', $correo->getSubject());
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $this->aDirecciones($correo->getTo()));
        $adjuntos = $this->adjuntos($correo);
        $this->assertSame(['RIDE-'.$factura->numero.'.pdf', $sri->clave_acceso.'.xml'], array_keys($adjuntos));
        $this->assertStringStartsWith('%PDF', $adjuntos['RIDE-'.$factura->numero.'.pdf']->getBody());
        $autorizado = simplexml_load_string($adjuntos[$sri->clave_acceso.'.xml']->getBody());
        $this->assertSame('autorizacion', $autorizado->getName());
        $this->assertSame('factura', simplexml_load_string((string) $autorizado->comprobante)->getName());

        $log = EmailLog::firstOrFail();
        $this->assertSame('enviado', $log->estado);
        $this->assertTrue($log->automatico);
        $this->assertNull($log->user_id);
        $this->assertSame($factura->id, $log->documentable_id);
        $this->assertSame(1, EmailLog::count());
    }

    public function test_la_nota_de_credito_sale_con_su_numero_aunque_lo_guarda_despues_de_emitirse(): void
    {
        $this->conCertificado();
        $this->sriFalso();
        $factura = $this->facturar();
        $this->automatico();
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'sri',
            'motivo' => 'Devolución parcial de servicios',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 1, 'precio_unitario' => 40, 'tarifa' => 15]],
        ])->assertCreated();
        $nota = CreditNote::latest('id')->firstOrFail();
        $this->assertNull($nota->numero, 'Antes de emitir la nota no tiene número');

        $this->postJson("/api/credit-notes/{$nota->id}/emit", ['company_id' => $this->company->id])->assertOk();

        $nota = $nota->fresh();
        $this->assertSame('001-001-000000002', $nota->numero);
        $this->assertCount(1, $this->transporte->messages());
        $correo = $this->correo();
        $this->assertSame('Nota de crédito 001-001-000000002 de Distribuidora Andina SA', $correo->getSubject());
        $this->assertArrayHasKey('RIDE-001-001-000000002.pdf', $this->adjuntos($correo));
        // La factura se emitió con el envío apagado (quedó "omitido"); la nota es el único correo que salió
        $this->assertSame(['omitido', 'enviado'], EmailLog::orderBy('id')->pluck('estado')->all());
        $this->assertSame('nota_credito', EmailLog::where('estado', 'enviado')->firstOrFail()->tipo_comprobante);
    }

    public function test_la_nota_de_debito_y_la_retencion_emitidas_tambien_salen_solas(): void
    {
        $this->conCertificado();
        $this->sriFalso();
        $factura = $this->facturar();
        $this->automatico();

        $id = $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => 'sri', 'forma_pago' => '01',
            'motivos' => [['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0]],
        ])->assertCreated()->json('nota_debito.id');
        $this->postJson("/api/notas-debito/{$id}/emit", ['company_id' => $this->company->id])->assertOk();
        $nd = Invoice::findOrFail($id);
        $this->assertCount(1, $this->transporte->messages());
        $this->assertSame('Nota de débito '.$nd->numero.' de Distribuidora Andina SA', $this->correo(0)->getSubject());

        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000099', 'fecha_emision' => '2026-10-05',
            'items' => [['codigo_principal' => 'RET-BIEN', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();
        $compra = Purchase::where('company_id', $this->company->id)->firstOrFail();
        $resp = $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100], ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15]],
        ])->assertCreated();

        $this->assertCount(2, $this->transporte->messages());
        $correo = $this->correo(1);
        $this->assertSame('Comprobante de retención '.$resp->json('numero').' de Distribuidora Andina SA', $correo->getSubject());
        $this->assertSame(['prov@proveedor.test'], $this->aDirecciones($correo->getTo()), 'La retención va al proveedor');
        $this->assertArrayHasKey('RIDE-'.$resp->json('numero').'.pdf', $this->adjuntos($correo));
        $this->assertSame(['nota_debito', 'retencion'], EmailLog::where('estado', 'enviado')->orderBy('id')->pluck('tipo_comprobante')->all());
    }

    // ------------------------------------------------------------ "Autorizar todo": otro camino que llega al mismo punto

    public function test_autorizar_todo_envia_el_correo_de_lo_que_se_autoriza_y_no_lo_repite(): void
    {
        $this->conCertificado();
        $this->automatico();
        $this->sriFalso();
        $factura = $this->factura('enviado');
        $this->assertSame(0, $this->transporte->messages()->count());

        $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('autorizados', 1);

        $this->assertSame('AUTORIZADO', $factura->sriDocument()->firstOrFail()->estado);
        $this->assertCount(1, $this->transporte->messages());
        // Una segunda corrida ya no tiene nada pendiente: no hay un segundo correo
        $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('procesados', 0);
        $this->assertCount(1, $this->transporte->messages());
        $this->assertSame(1, EmailLog::where('estado', 'enviado')->count());
    }

    public function test_nunca_se_envia_dos_veces_de_forma_automatica_el_mismo_documento(): void
    {
        $this->automatico();
        $factura = $this->factura('enviado');
        $sri = $factura->sriDocument()->firstOrFail();

        $sri->update(['estado' => 'AUTORIZADO']);
        $this->assertCount(1, $this->transporte->messages());

        // Guardar otra vez, volver a llamar al servicio o llamarlo a mano: ninguno repite el envío automático
        $sri->update(['numero_autorizacion' => 'otro']);
        $sri->update(['estado' => 'AUTORIZADO']);
        $r = app(EnviarComprobanteCorreo::class)->alAutorizarse($sri->fresh());
        $this->assertSame('omitido', $r->estado);
        $this->assertStringContainsString('ya se envió', $r->mensaje);
        $this->assertCount(1, $this->transporte->messages());
        $this->assertSame(1, EmailLog::count(), 'Ni siquiera una fila de más en la bitácora');
    }

    // ------------------------------------------------------------ cuándo NO se envía

    public function test_sin_certificado_el_comprobante_queda_generado_y_no_sale_nada(): void
    {
        $this->automatico();
        $this->sriFalso();   // aunque el SRI autorizara todo, sin firma no hay con qué enviarle nada
        $this->assertNull($this->company->fresh()->certificado_p12);

        $factura = $this->facturar();

        $this->assertSame('generado', $factura->sriDocument()->firstOrFail()->estado);
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count(), 'Ni un intento: no hay autorización');
    }

    public function test_si_el_sri_todavia_no_autoriza_no_se_envia_nada(): void
    {
        $this->conCertificado();
        $this->automatico();
        $this->sriFalso(false);

        $factura = $this->facturar();

        $this->assertSame('enviado', $factura->sriDocument()->firstOrFail()->estado);
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_con_el_envio_automatico_apagado_la_autorizacion_solo_deja_constancia(): void
    {
        $this->conCertificado();
        $this->sriFalso();
        $this->assertFalse($this->company->fresh()->enviar_comprobantes_automaticamente);

        $factura = $this->facturar();

        $this->assertSame('AUTORIZADO', $factura->sriDocument()->firstOrFail()->estado);
        $this->assertCount(0, $this->transporte->messages());
        $log = EmailLog::firstOrFail();
        $this->assertSame('omitido', $log->estado);
        $this->assertTrue($log->automatico);
        $this->assertStringContainsString('desactivado', $log->motivo);
    }

    public function test_un_comprobante_que_nace_autorizado_como_los_xml_importados_no_dispara_el_envio(): void
    {
        $this->automatico();

        $this->factura('AUTORIZADO');   // se crea ya autorizado, igual que los que se importan de un XML

        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_una_factura_anulada_en_el_sistema_no_se_envia_al_autorizarse(): void
    {
        $this->automatico();
        $factura = $this->factura('enviado');
        $factura->update(['estado' => 'anulado']);

        $factura->sriDocument()->firstOrFail()->update(['estado' => 'AUTORIZADO']);

        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_si_se_deshace_la_transaccion_no_sale_ningun_correo_y_si_se_confirma_sale_despues(): void
    {
        $this->automatico();
        $sri = $this->factura('enviado')->sriDocument()->firstOrFail();

        try {
            DB::transaction(function () use ($sri) {
                $sri->update(['estado' => 'AUTORIZADO']);
                throw new \RuntimeException('se deshace');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(0, $this->transporte->messages(), 'La autorización se deshizo: no hay nada que mandar');
        $this->assertSame(0, EmailLog::count());

        $sri = $sri->fresh();
        DB::transaction(function () use ($sri) {
            $sri->update(['estado' => 'AUTORIZADO']);
            $this->assertCount(0, $this->transporte->messages(), 'Dentro de la transacción todavía no sale');
        });
        $this->assertCount(1, $this->transporte->messages(), 'Al confirmarse la transacción sale');
    }

    // ------------------------------------------------------------ el correo nunca rompe la autorización

    public function test_si_el_correo_falla_la_factura_sigue_emitida_y_autorizada_y_queda_el_error_sin_secretos(): void
    {
        $this->conCertificado();
        $this->automatico();
        $this->sriFalso();
        $this->servidorQueFalla();
        Log::spy();

        $resp = $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 50, 'tarifa' => 15]],
        ]);

        $resp->assertCreated();
        $factura = Invoice::latest('id')->firstOrFail();
        $this->assertSame('emitida', $factura->estado);
        $this->assertSame('AUTORIZADO', $factura->sriDocument()->firstOrFail()->estado, 'La autorización no se toca');
        $this->assertSame(0, $this->transporte->messages()->count());
        $log = EmailLog::firstOrFail();
        $this->assertSame('error', $log->estado);
        $this->assertTrue($log->automatico);
        $this->assertStringNotContainsString(self::CLAVE_SMTP, json_encode($log->toArray()).$resp->getContent());
        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (...$args) {
            $this->assertStringNotContainsString(self::CLAVE_SMTP, json_encode($args));

            return true;
        });
    }

    public function test_si_el_servidor_no_responde_autorizar_todo_no_espera_una_vez_por_comprobante(): void
    {
        $this->conCertificado();
        $this->automatico();
        $this->sriFalso();
        $this->servidorQueFalla('Connection could not be established with host "smtp.andina.test:587": timed out');
        $facturas = [$this->factura('enviado'), $this->factura('enviado'), $this->factura('enviado')];

        $r = $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk();

        $r->assertJsonPath('autorizados', 3);
        foreach ($facturas as $f) {
            $this->assertSame('AUTORIZADO', $f->sriDocument()->firstOrFail()->estado);
        }
        $this->assertCount(1, $this->capt->configs, 'Solo se intentó conectar una vez: el servidor ya había fallado');
        $logs = EmailLog::orderBy('id')->get();
        $this->assertSame(['error', 'error', 'error'], $logs->pluck('estado')->all());
        $this->assertStringContainsString('No se pudo conectar', $logs[0]->motivo);
        $this->assertStringContainsString('No se intentó', $logs[1]->motivo);
        $this->assertStringContainsString('No se intentó', $logs[2]->motivo);
    }

    public function test_se_engancha_una_sola_vez_en_el_modelo_y_no_en_cada_camino(): void
    {
        // El envío automático vive solo en SriDocument::booted y en el servicio; ni la emisión ni el lote lo llaman
        $raiz = base_path('app');
        $llamadas = [];
        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz))) as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php' && str_contains((string) file_get_contents($archivo->getPathname()), 'alAutorizarse')) {
                $llamadas[] = str_replace($raiz.'/', '', $archivo->getPathname());
            }
        }
        sort($llamadas);
        $this->assertSame(['Models/SriDocument.php', 'Services/EnviarComprobanteCorreo.php'], $llamadas);
    }
}
