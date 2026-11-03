<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T10.2b y T10.3 (lado del servidor): el envío MANUAL por la API, el historial, la columna "Correo" de Documentos SRI y la
 * configuración del correo en e-documents (interruptor del envío automático, clave cifrada, correo de prueba).
 */
class CorreoComprobanteManualTest extends CorreoTestCase
{
    private function enviar(string $tipo, int $id, array $cuerpo = [], ?int $empresa = null)
    {
        return $this->postJson("/api/comprobantes/{$tipo}/{$id}/enviar-correo", ['company_id' => $empresa ?? $this->company->id] + $cuerpo);
    }

    // ------------------------------------------------------------ enviar

    public function test_el_envio_manual_usa_los_correos_del_cliente_y_deja_quien_lo_hizo(): void
    {
        $factura = $this->factura();
        $usuario = $this->actuarComo('cajero');

        $r = $this->enviar('factura', $factura->id)->assertOk();

        $r->assertJsonPath('estado', 'enviado')->assertJsonPath('ok', true);
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $r->json('destinatarios'));
        $this->assertStringContainsString('Correo enviado a maria@cliente.test', $r->json('mensaje'));
        $this->assertCount(1, $this->transporte->messages());
        $log = EmailLog::firstOrFail();
        $this->assertFalse($log->automatico);
        $this->assertSame($usuario->id, $log->user_id);
        $r->assertJsonPath('correo.id', $log->id);
    }

    public function test_se_pueden_elegir_otros_destinatarios_y_se_validan(): void
    {
        $factura = $this->factura();

        $this->enviar('factura', $factura->id, ['destinatarios' => ['otro@cliente.test', 'OTRO@cliente.test', 'tercero@cliente.test']])->assertOk()
            ->assertJsonPath('estado', 'enviado');
        $this->assertSame(['otro@cliente.test', 'tercero@cliente.test'], $this->aDirecciones($this->correo()->getTo()), 'Solo los pedidos, sin repetir');

        $r = $this->enviar('factura', $factura->id, ['destinatarios' => ['bien@cliente.test', 'esto-no-es-un-correo']])->assertStatus(422);
        $this->assertSame('El correo "esto-no-es-un-correo" no es válido.', $r->json('errors')['destinatarios.1'][0]);
        $this->enviar('factura', $factura->id, ['destinatarios' => ['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test', 'e@x.test', 'f@x.test']])->assertStatus(422);
        $this->enviar('factura', $factura->id, ['destinatarios' => [str_repeat('a', 160).'@x.test']])->assertStatus(422);
        $this->assertCount(1, $this->transporte->messages(), 'Lo inválido no envía nada');
    }

    public function test_sin_correo_del_cliente_ni_destinatarios_responde_omitido_sin_enviar(): void
    {
        $this->cliente->update(['email' => null, 'email2' => null]);
        $factura = $this->factura();

        $r = $this->enviar('factura', $factura->id)->assertOk();

        $r->assertJsonPath('estado', 'omitido')->assertJsonPath('ok', false);
        $this->assertStringContainsString('no tiene un correo electrónico', $r->json('mensaje'));
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame('omitido', EmailLog::firstOrFail()->estado);
    }

    public function test_funciona_aunque_el_sri_todavia_no_lo_autorice_y_el_correo_lo_dice(): void
    {
        $factura = $this->factura('generado');

        $this->enviar('factura', $factura->id)->assertOk()->assertJsonPath('estado', 'enviado');

        $correo = $this->correo();
        $this->assertStringContainsString('pendiente de autorización del SRI', $correo->getHtmlBody());
        $this->assertStringNotContainsString('Número de autorización', $correo->getHtmlBody());
        $this->assertStringStartsWith('%PDF', array_values($this->adjuntos($correo))[0]->getBody());
    }

    public function test_funciona_con_notas_de_credito_notas_de_debito_y_retenciones(): void
    {
        $nota = $this->notaCredito();
        $nd = $this->notaDebito();
        $ret = $this->retencion();

        $this->enviar('nota_credito', $nota->id)->assertOk()->assertJsonPath('estado', 'enviado');
        $this->enviar('nota-debito', $nd->id)->assertOk()->assertJsonPath('estado', 'enviado');   // el guion también vale
        // Una retención se envía con el id de cualquiera de sus filas y siempre es UN solo comprobante
        $segunda = \App\Models\Withholding::where('numero', $ret->numero)->orderByDesc('id')->firstOrFail();
        $r = $this->enviar('retencion', $segunda->id)->assertOk()->assertJsonPath('estado', 'enviado');

        $this->assertSame(['prov@proveedor.test'], $r->json('destinatarios'));
        $this->assertCount(3, $this->transporte->messages());
        $this->assertSame(['nota_credito', 'nota_debito', 'retencion'], EmailLog::orderBy('id')->pluck('tipo_comprobante')->all());
        $this->assertSame($ret->id, EmailLog::where('tipo_comprobante', 'retencion')->firstOrFail()->documentable_id, 'La bitácora cuelga de la fila principal');
    }

    public function test_una_falla_real_del_correo_responde_200_con_el_motivo_y_sin_la_clave(): void
    {
        $this->servidorQueFalla();
        Log::spy();
        $factura = $this->factura();

        $r = $this->enviar('factura', $factura->id)->assertOk();

        $r->assertJsonPath('estado', 'error')->assertJsonPath('ok', false);
        $this->assertStringContainsString('rechazó el usuario o la clave', $r->json('mensaje'));
        $this->assertStringNotContainsString(self::CLAVE_SMTP, $r->getContent());
        $this->assertSame('error', EmailLog::firstOrFail()->estado);
    }

    // ------------------------------------------------------------ quién puede y qué documentos

    public function test_admin_contador_y_cajero_pueden_enviar_y_sin_sesion_no(): void
    {
        $factura = $this->factura();
        foreach (['admin', 'contador', 'cajero'] as $rol) {
            $this->actuarComo($rol);
            $this->enviar('factura', $factura->id)->assertOk();
        }
        $this->assertCount(3, $this->transporte->messages());

        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['rol' => 'cajero', 'activo' => false]));
        $this->enviar('factura', $factura->id)->assertForbidden();
    }

    public function test_el_documento_de_otra_empresa_no_existe_para_quien_lo_pide(): void
    {
        $otra = $this->empresa();
        $contactoOtra = Contact::create(['company_id' => $otra->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002', 'razon_social' => 'Otro', 'email' => 'otro@x.test']);
        $ajena = $this->factura('AUTORIZADO', $otra, $contactoOtra);

        // Pidiéndola desde mi empresa
        $this->enviar('factura', $ajena->id)->assertNotFound();
        $this->getJson("/api/comprobantes/factura/{$ajena->id}/correos?company_id=".$this->company->id)->assertNotFound();
        // Un usuario que no es admin tampoco puede pedir la empresa ajena (T11.6: 403 del aislamiento, antes 404 del controlador)
        $this->actuarComo('cajero', $this->company);
        $this->enviar('factura', $ajena->id, [], $otra->id)->assertForbidden();
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_tipo_desconocido_documento_inexistente_o_de_otro_tipo_dan_404(): void
    {
        $factura = $this->factura();
        $recibida = \App\Models\Withholding::create(['company_id' => $this->company->id, 'tipo' => 'recibida', 'numero' => '001-001-000000500', 'fecha' => '2026-10-01',
            'base_imponible' => 10, 'porcentaje' => 10, 'total_retenido' => 1]);

        $this->enviar('guia', $factura->id)->assertNotFound();
        $this->enviar('factura', 987654)->assertNotFound();
        $this->enviar('nota_credito', 999999)->assertNotFound();
        $this->enviar('retencion', $recibida->id)->assertNotFound();    // la retención recibida no es nuestra
        $nd = $this->notaDebito();
        $this->enviar('factura', $nd->id)->assertNotFound();            // una nota de débito no es una factura
        $this->enviar('nota_debito', $factura->id)->assertNotFound();
        $this->postJson("/api/comprobantes/factura/{$factura->id}/enviar-correo", [])->assertStatus(422);   // company_id es obligatorio
    }

    public function test_las_notas_internas_no_se_envian_por_correo(): void
    {
        $interna = $this->notaCredito('AUTORIZADO', 'interna');
        $ndInterna = $this->notaDebito('AUTORIZADO', 'nota_debito_interna');

        $r = $this->enviar('nota_credito', $interna->id)->assertStatus(422);
        $this->assertSame('Las notas internas no se envían por correo.', $r->json('message'));
        $r = $this->enviar('nota_debito', $ndInterna->id)->assertStatus(422);
        $this->assertSame('Las notas internas no se envían por correo.', $r->json('message'));
        $this->getJson("/api/comprobantes/nota_credito/{$interna->id}/correos?company_id=".$this->company->id)->assertStatus(422);
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_una_factura_sin_comprobante_del_sri_no_se_envia(): void
    {
        $factura = $this->factura();
        $factura->sriDocument()->delete();

        $r = $this->enviar('factura', $factura->id)->assertStatus(422);

        $this->assertStringContainsString('no tiene comprobante electrónico del SRI', $r->json('message'));
    }

    // ------------------------------------------------------------ historial

    public function test_el_historial_lista_los_envios_con_sus_motivos_y_los_correos_sugeridos(): void
    {
        $factura = $this->factura();
        $usuario = $this->actuarComo('contador');
        $this->enviar('factura', $factura->id)->assertOk();
        $this->servidorQueFalla();
        $this->enviar('factura', $factura->id, ['destinatarios' => ['otro@cliente.test']])->assertOk()->assertJsonPath('estado', 'error');

        $r = $this->getJson("/api/comprobantes/factura/{$factura->id}/correos?company_id=".$this->company->id)->assertOk();

        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $r->json('sugeridos'));
        $this->assertTrue($r->json('autorizado'));
        $this->assertTrue($r->json('smtp_configurado'));
        $this->assertFalse($r->json('envio_automatico'));
        $correos = $r->json('correos');
        $this->assertCount(2, $correos);
        $this->assertSame(['error', 'enviado'], array_column($correos, 'estado'), 'El más reciente primero');
        $this->assertSame(['otro@cliente.test'], $correos[0]['destinatarios']);
        $this->assertStringContainsString('rechazó', $correos[0]['motivo']);
        $this->assertSame($usuario->name, $correos[1]['user']['name']);
        $this->assertStringNotContainsString(self::CLAVE_SMTP, $r->getContent());
    }

    // ------------------------------------------------------------ Documentos SRI: la columna "Correo"

    public function test_documentos_sri_trae_el_estado_del_correo_de_toda_la_lista_con_pocas_consultas(): void
    {
        $f1 = $this->factura('generado');
        $f2 = $this->factura('firmado');
        $nota = $this->notaCredito('generado');
        $interna = $this->notaCredito('generado', 'interna');   // su factura sí tiene comprobante; la nota interna no
        $this->enviar('factura', $f1->id)->assertOk();

        $pendientes = $this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->assertOk();
        $porNumero = collect($pendientes->json())->keyBy('numero');
        $this->assertSame('enviado', $porNumero[$f1->numero]['correo']['estado']);
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $porNumero[$f1->numero]['correo']['destinatarios']);
        $this->assertNotNull($porNumero[$f1->numero]['correo']['enviado_en']);
        $this->assertSame('sin_enviar', $porNumero[$f2->numero]['correo']['estado']);
        $this->assertSame(['tipo' => 'factura', 'id' => $f2->id], $porNumero[$f2->numero]['comprobante']);
        $this->assertSame(['tipo' => 'nota_credito', 'id' => $nota->id], $porNumero[$nota->numero]['comprobante']);

        // Las mismas consultas con 3 comprobantes que con 12: no hay una consulta por fila
        $consultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $antes = $consultas();
        for ($i = 0; $i < 9; $i++) {
            $this->factura('generado');
        }
        $this->assertSame($antes, $consultas(), 'Las consultas no crecen con el número de comprobantes');
    }

    public function test_la_lista_de_autorizados_muestra_el_cliente_y_el_correo_sin_consultas_por_fila(): void
    {
        $a = $this->factura('AUTORIZADO');
        $this->factura('AUTORIZADO');
        $this->factura('generado');
        $this->enviar('factura', $a->id)->assertOk();
        $this->servidorQueFalla();
        $b = Invoice::where('numero', '!=', $a->numero)->whereHas('sriDocument', fn ($q) => $q->where('estado', 'AUTORIZADO'))->firstOrFail();
        $this->enviar('factura', $b->id)->assertOk()->assertJsonPath('estado', 'error');

        $r = $this->getJson('/api/sri-documents/authorized?company_id='.$this->company->id)->assertOk();

        $filas = collect($r->json())->keyBy('numero');
        $this->assertCount(2, $filas, 'Solo los autorizados');
        $this->assertSame('enviado', $filas[$a->numero]['correo']['estado']);
        $this->assertSame('error', $filas[$b->numero]['correo']['estado']);
        $this->assertSame('María Fernanda Pazmiño', $filas[$a->numero]['cliente']);

        $consultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/sri-documents/authorized?company_id='.$this->company->id)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $antes = $consultas();
        for ($i = 0; $i < 8; $i++) {
            $this->factura('AUTORIZADO');
        }
        $this->assertSame($antes, $consultas());
    }

    // ------------------------------------------------------------ configuración (e-documents)

    public function test_la_configuracion_guarda_el_servidor_de_correo_cifra_la_clave_y_nunca_la_devuelve(): void
    {
        $vacia = $this->empresa(['smtp_host' => null, 'smtp_port' => null, 'smtp_user' => null, 'smtp_password' => null, 'email_envio' => null]);
        $this->assertFalse($this->getJson("/api/companies/{$vacia->id}/edoc-config")->assertOk()->json('smtp_configurado'));

        $r = $this->postJson("/api/companies/{$vacia->id}/edoc-config", [
            'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_user' => 'ventas@mi-empresa.test', 'smtp_password' => 'Abc-123-secreta',
            'smtp_ssl' => true, 'email_envio' => 'ventas@mi-empresa.test', 'enviar_comprobantes_automaticamente' => true,
        ])->assertOk();

        $cfg = $r->json('config');
        $this->assertSame(['smtp.gmail.com', 587, 'ventas@mi-empresa.test', true, 'ventas@mi-empresa.test', true, true, true],
            [$cfg['smtp_host'], $cfg['smtp_port'], $cfg['smtp_user'], $cfg['smtp_ssl'], $cfg['email_envio'], $cfg['enviar_comprobantes_automaticamente'], $cfg['smtp_password_guardada'], $cfg['smtp_configurado']]);
        $this->assertStringNotContainsString('Abc-123-secreta', $r->getContent());
        $crudo = DB::table('companies')->where('id', $vacia->id)->value('smtp_password');
        $this->assertNotNull($crudo);
        $this->assertStringNotContainsString('Abc-123-secreta', (string) $crudo, 'En la base va cifrada');
        $this->assertSame('Abc-123-secreta', $vacia->fresh()->smtp_password);

        // Guardar otra cosa sin escribir la clave no la borra; y apagar el interruptor se guarda
        $this->postJson("/api/companies/{$vacia->id}/edoc-config", ['smtp_host' => 'smtp.otro.test', 'enviar_comprobantes_automaticamente' => false])->assertOk();
        $this->assertSame('Abc-123-secreta', $vacia->fresh()->smtp_password);
        $this->assertFalse($vacia->fresh()->enviar_comprobantes_automaticamente);
        $this->assertSame('smtp.otro.test', $vacia->fresh()->smtp_host);
        // La empresa no la entrega con la clave ni por la API de empresas
        $this->assertStringNotContainsString('Abc-123-secreta', $this->getJson("/api/companies/{$vacia->id}")->assertOk()->getContent());
        $this->assertStringNotContainsString('Abc-123-secreta', $this->getJson('/api/companies')->assertOk()->getContent());
    }

    public function test_solo_el_administrador_cambia_el_correo_y_el_interruptor(): void
    {
        $this->actuarComo('contador');
        $this->postJson("/api/companies/{$this->company->id}/edoc-config", ['enviar_comprobantes_automaticamente' => true])->assertForbidden();
        $this->assertFalse($this->company->fresh()->enviar_comprobantes_automaticamente);
        $this->postJson("/api/companies/{$this->company->id}/edoc-config", ['enviar_comprobantes_automaticamente' => 'quizas'])->assertForbidden();
        $this->actuarComo('admin');
        $this->postJson("/api/companies/{$this->company->id}/edoc-config", ['enviar_comprobantes_automaticamente' => 'quizas'])->assertStatus(422);
    }

    public function test_el_correo_de_prueba_usa_el_mismo_camino_que_los_comprobantes(): void
    {
        $r = $this->postJson("/api/companies/{$this->company->id}/smtp/test", ['destinatario' => 'yo@mi-correo.test'])->assertOk();

        $this->assertStringContainsString('Correo de prueba enviado a yo@mi-correo.test', $r->json('mensaje'));
        $this->assertCount(1, $this->transporte->messages());
        $correo = $this->correo();
        $this->assertSame(['yo@mi-correo.test'], $this->aDirecciones($correo->getTo()));
        $this->assertSame('facturas@andina.test', $correo->getFrom()[0]->getAddress());
        $this->assertStringContainsString('Prueba de correo', $correo->getSubject());
        $this->assertSame('smtp.andina.test', $this->capt->configs[0]['host']);
        $this->assertSame(0, EmailLog::count(), 'La prueba no es un comprobante: no va a la bitácora');
    }

    public function test_el_correo_de_prueba_sin_configurar_o_con_error_responde_422_sin_la_clave(): void
    {
        $this->postJson("/api/companies/{$this->company->id}/smtp/test", ['destinatario' => 'no-es-correo'])->assertStatus(422);

        $sinConfig = $this->empresa(['smtp_host' => null]);
        $r = $this->postJson("/api/companies/{$sinConfig->id}/smtp/test", ['destinatario' => 'yo@mi-correo.test'])->assertStatus(422);
        $this->assertStringContainsString('Configura el servidor de correo primero', $r->json('error'));

        $this->servidorQueFalla();
        $r = $this->postJson("/api/companies/{$this->company->id}/smtp/test", ['destinatario' => 'yo@mi-correo.test'])->assertStatus(422);
        $this->assertStringContainsString('rechazó el usuario o la clave', $r->json('error'));
        $this->assertStringNotContainsString(self::CLAVE_SMTP, $r->getContent());
        $this->assertCount(0, $this->transporte->messages());
    }
}
