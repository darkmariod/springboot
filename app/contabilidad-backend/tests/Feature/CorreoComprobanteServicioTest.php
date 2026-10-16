<?php

namespace Tests\Feature;

use App\Mail\ComprobanteMail;
use App\Models\EmailLog;
use App\Models\SriDocument;
use App\Services\EnviarComprobanteCorreo;
use App\Services\RideBuilder;
use App\Support\TextoSeguro;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * T10.1: el servicio que arma y envía el correo de un comprobante con el RIDE (PDF) y el XML adjuntos, por el servidor de correo
 * de la empresa, y deja su bitácora. Sin red: el transporte SMTP es uno en memoria (ver CorreoTestCase).
 */
class CorreoComprobanteServicioTest extends CorreoTestCase
{
    private function servicio(): EnviarComprobanteCorreo
    {
        return app(EnviarComprobanteCorreo::class);
    }

    // ------------------------------------------------------------ el correo con sus dos adjuntos

    public function test_una_factura_autorizada_sale_con_el_ride_en_pdf_y_el_xml_autorizado(): void
    {
        $factura = $this->factura('AUTORIZADO');
        $sri = $factura->sriDocument()->firstOrFail();

        $r = $this->servicio()->enviar($factura, ['maria@cliente.test', 'contabilidad@cliente.test']);

        $this->assertSame('enviado', $r->estado);
        $this->assertCount(1, $this->transporte->messages());
        $correo = $this->correo();

        $this->assertSame('Factura 001-001-000000001 de Distribuidora Andina SA', $correo->getSubject());
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $this->aDirecciones($correo->getTo()));
        $this->assertSame('facturas@andina.test', $correo->getFrom()[0]->getAddress());
        $this->assertSame('Distribuidora Andina SA', $correo->getFrom()[0]->getName());

        $adjuntos = $this->adjuntos($correo);
        $this->assertSame(['RIDE-001-001-000000001.pdf', $sri->clave_acceso.'.xml'], array_keys($adjuntos));

        $pdf = $adjuntos['RIDE-001-001-000000001.pdf'];
        $this->assertSame('application/pdf', $pdf->getMediaType().'/'.$pdf->getMediaSubtype());
        $this->assertStringStartsWith('%PDF', $pdf->getBody());
        $this->assertGreaterThan(1000, strlen($pdf->getBody()));

        // El XML autorizado: el nodo <autorizacion> del SRI con el comprobante firmado adentro
        $xml = $adjuntos[$sri->clave_acceso.'.xml'];
        $this->assertSame('application/xml', $xml->getMediaType().'/'.$xml->getMediaSubtype());
        $autorizado = simplexml_load_string($xml->getBody());
        $this->assertNotFalse($autorizado, 'El XML adjunto tiene que ser un XML válido');
        $this->assertSame('autorizacion', $autorizado->getName());
        $this->assertSame('AUTORIZADO', (string) $autorizado->estado);
        $this->assertSame($sri->clave_acceso, (string) $autorizado->numeroAutorizacion);
        $this->assertSame('2026-10-07T10:15:30-05:00', (string) $autorizado->fechaAutorizacion);
        $this->assertSame('PRUEBAS', (string) $autorizado->ambiente);
        $interno = simplexml_load_string((string) $autorizado->comprobante);
        $this->assertNotFalse($interno, 'El comprobante dentro del CDATA es otro XML válido');
        $this->assertSame('factura', $interno->getName());
        $this->assertSame($sri->clave_acceso, (string) $interno->infoTributaria->claveAcceso);

        // El cuerpo: número, fecha, total, emisor, autorización y la línea de los adjuntos
        $html = $correo->getHtmlBody();
        foreach (['001-001-000000001', now()->format('d/m/Y'), '$115.00', 'Distribuidora Andina SA', $sri->clave_acceso, '07/10/2026 10:15:30',
            'RIDE', 'XML', 'María Fernanda Pazmiño'] as $dato) {
            $this->assertStringContainsString($dato, $html);
        }
        $this->assertStringNotContainsString('pendiente de autorización', $html);
        $this->assertStringContainsString('Número de autorización', $correo->getTextBody());
        // Sin rastreo ni imágenes remotas
        $this->assertDoesNotMatchRegularExpression('/<img\b/i', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    public function test_un_comprobante_sin_autorizar_dice_que_esta_pendiente_y_no_inventa_autorizacion(): void
    {
        $factura = $this->factura('generado');
        $sri = $factura->sriDocument()->firstOrFail();

        $r = $this->servicio()->enviar($factura, ['maria@cliente.test']);

        $this->assertSame('enviado', $r->estado);
        $correo = $this->correo();
        $adjuntos = $this->adjuntos($correo);
        // El RIDE (con su leyenda) y el XML generado, nombrado como NO autorizado
        $this->assertSame(['RIDE-001-001-000000001.pdf', $sri->clave_acceso.'-sin-autorizacion.xml'], array_keys($adjuntos));
        $xml = simplexml_load_string($adjuntos[$sri->clave_acceso.'-sin-autorizacion.xml']->getBody());
        $this->assertSame('factura', $xml->getName(), 'Sin autorización el XML es el del comprobante, sin el nodo de autorización');
        $this->assertStringContainsString('pendiente de autorización del SRI', $correo->getHtmlBody());
        $this->assertStringNotContainsString('Número de autorización', $correo->getHtmlBody());
        $this->assertStringNotContainsString('Número de autorización', $correo->getTextBody());
    }

    public function test_sin_ningun_xml_solo_va_el_ride_y_la_bitacora_lo_dice(): void
    {
        $factura = $this->factura('generado');
        $factura->sriDocument()->firstOrFail()->update(['xml' => null, 'xml_firmado' => null]);

        $r = $this->servicio()->enviar($factura->fresh(), ['maria@cliente.test']);

        $this->assertSame('enviado', $r->estado);
        $this->assertSame(['RIDE-001-001-000000001.pdf'], array_keys($this->adjuntos($this->correo())));
        $log = EmailLog::firstOrFail();
        $this->assertStringContainsString('sin xml', mb_strtolower($log->motivo));
        $this->assertContains('El comprobante no tiene XML generado.', array_column($log->adjuntos, 'omitido'));
    }

    public function test_la_nota_de_credito_sin_numero_propio_usa_el_de_su_comprobante(): void
    {
        // La nota del SRI guarda su número DESPUÉS de emitirse; al autorizarse todavía no lo tiene
        $nota = $this->notaCredito('AUTORIZADO');
        $nota->update(['numero' => null]);
        $esperado = $nota->sriDocument()->firstOrFail()->numeroComprobante();
        $this->assertSame('001-001-000000002', $esperado);

        $r = $this->servicio()->enviar($nota->fresh(), ['maria@cliente.test']);

        $this->assertSame('enviado', $r->estado);
        $this->assertSame('Nota de crédito 001-001-000000002 de Distribuidora Andina SA', $this->correo()->getSubject());
        $this->assertArrayHasKey('RIDE-001-001-000000002.pdf', $this->adjuntos($this->correo()));
        $ride = app(RideBuilder::class)->paraNotaCredito($nota->fresh());
        $this->assertSame('001-001-000000002', $ride['numero']);
    }

    public function test_la_nota_de_debito_y_la_retencion_tambien_salen_con_su_ride_y_su_xml(): void
    {
        $nd = $this->notaDebito('AUTORIZADO');
        $r = $this->servicio()->enviar($nd, ['maria@cliente.test']);
        $this->assertSame('enviado', $r->estado);
        $this->assertSame('Nota de débito '.$nd->numero.' de Distribuidora Andina SA', $this->correo(0)->getSubject());
        $this->assertSame(['RIDE-'.$nd->numero.'.pdf', $nd->sriDocument()->firstOrFail()->clave_acceso.'.xml'], array_keys($this->adjuntos($this->correo(0))));

        $ret = $this->retencion('AUTORIZADO');
        $r = $this->servicio()->enviar($ret, ['prov@proveedor.test']);
        $this->assertSame('enviado', $r->estado);
        $correo = $this->correo(1);
        $this->assertSame('Comprobante de retención '.$ret->numero.' de Distribuidora Andina SA', $correo->getSubject());
        $adjuntos = $this->adjuntos($correo);
        $this->assertSame(['RIDE-'.$ret->numero.'.pdf', $ret->sriDocument()->firstOrFail()->clave_acceso.'.xml'], array_keys($adjuntos));
        $this->assertStringStartsWith('%PDF', $adjuntos['RIDE-'.$ret->numero.'.pdf']->getBody());
        $this->assertStringContainsString('Valor retenido', $correo->getHtmlBody());
        $this->assertStringContainsString('$25.00', $correo->getHtmlBody(), 'Renta 10.00 + IVA 15.00');
    }

    public function test_el_ride_de_la_retencion_lleva_sus_lineas_y_el_total_retenido(): void
    {
        $ret = $this->retencion('AUTORIZADO');
        // Cualquiera de las filas del comprobante da el mismo RIDE completo
        $segunda = \App\Models\Withholding::where('numero', $ret->numero)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($ret->id, $segunda->id);

        $d = app(RideBuilder::class)->paraRetencion($segunda);

        $this->assertSame('COMPROBANTE DE RETENCIÓN', $d['tipo']);
        $this->assertSame($ret->numero, $d['numero']);
        $this->assertSame('retenciones', $d['detalle']);
        $this->assertSame('Proveedor Test SA', $d['comprador']['razon_social']);
        $this->assertSame('10/2026', $d['periodo_fiscal']);
        $this->assertCount(2, $d['lineas']);
        $this->assertSame(['RENTA', '303', '100.00', '10', '10.00'], [$d['lineas'][0]['impuesto'], $d['lineas'][0]['codigo'], $d['lineas'][0]['base'], $d['lineas'][0]['porcentaje'], $d['lineas'][0]['valor']]);
        $this->assertSame(['IVA', '725', '15.00', '100', '15.00'], [$d['lineas'][1]['impuesto'], $d['lineas'][1]['codigo'], $d['lineas'][1]['base'], $d['lineas'][1]['porcentaje'], $d['lineas'][1]['valor']]);
        $this->assertSame('FACTURA No. 001-001-000000099', $d['lineas'][0]['sustento']);
        $this->assertSame('05/10/2026', $d['lineas'][0]['fecha_sustento']);
        $this->assertSame(['RETENCIÓN RENTA' => '10.00', 'RETENCIÓN IVA' => '15.00', 'TOTAL RETENIDO' => '25.00'], array_column($d['totales'], 'valor', 'etiqueta'));
        $this->assertTrue($d['autorizacion']['autorizado']);
        // Y la vista la dibuja sin errores
        $html = view('ride.documento', ['d' => $d])->render();
        $this->assertStringContainsString('COMPROBANTE DE RETENCIÓN', $html);
        $this->assertStringContainsString('Valor retenido', $html);
        $this->assertStringContainsString('TOTAL RETENIDO', $html);
    }

    // ------------------------------------------------------------ el mailer propio de la empresa

    public function test_el_envio_usa_la_configuracion_de_la_empresa_sin_tocar_el_mailer_global(): void
    {
        $globalAntes = [config('mail.default'), config('mail.mailers.smtp'), config('mail.mailers')];

        $this->servicio()->enviar($this->factura(), ['maria@cliente.test']);

        $this->assertCount(1, $this->capt->configs);
        $cfg = $this->capt->configs[0];
        $this->assertSame('smtp', $cfg['transport']);
        $this->assertSame('smtp.andina.test', $cfg['host']);
        $this->assertSame(587, $cfg['port']);
        $this->assertSame('facturas@andina.test', $cfg['username']);
        $this->assertSame(self::CLAVE_SMTP, $cfg['password'], 'La clave llega descifrada al transporte');
        $this->assertSame('smtp', $cfg['scheme']);
        $this->assertTrue($cfg['require_tls'], 'Con "Usar SSL" y un puerto distinto de 465 se exige STARTTLS');
        $this->assertSame(EnviarComprobanteCorreo::TIEMPO_ESPERA, $cfg['timeout']);
        $this->assertLessThanOrEqual(10, $cfg['timeout'], 'Tiempo de espera corto: no hay worker de colas');
        // El mailer global quedó igual y el de la empresa no se queda en la configuración
        $this->assertSame($globalAntes, [config('mail.default'), config('mail.mailers.smtp'), config('mail.mailers')]);
        $this->assertNull(config('mail.mailers.correo_empresa_'.$this->company->id));
    }

    public function test_el_puerto_465_usa_ssl_desde_el_inicio_y_la_clave_se_guarda_cifrada(): void
    {
        $this->company->update(['smtp_port' => 465]);
        $cfg = $this->servicio()->configuracionSmtp($this->company->fresh());
        $this->assertSame('smtps', $cfg['scheme']);
        $this->assertArrayNotHasKey('require_tls', $cfg);

        $this->company->update(['smtp_port' => 2525, 'smtp_ssl' => false]);
        $cfg = $this->servicio()->configuracionSmtp($this->company->fresh());
        $this->assertSame(2525, $cfg['port']);
        $this->assertArrayNotHasKey('require_tls', $cfg);

        // En la base la clave NO está en claro
        $crudo = DB::table('companies')->where('id', $this->company->id)->value('smtp_password');
        $this->assertNotSame(self::CLAVE_SMTP, $crudo);
        $this->assertStringNotContainsString(self::CLAVE_SMTP, (string) $crudo);
        $this->assertSame(self::CLAVE_SMTP, $this->company->fresh()->smtp_password);
    }

    public function test_el_transporte_real_se_arma_con_el_host_el_puerto_y_las_credenciales_de_la_empresa(): void
    {
        // Sin el transporte falso: el que Laravel arma de verdad con esa configuración (solo se construye, no se conecta)
        $transporte = (new MailManager($this->app))->createSymfonyTransport($this->servicio()->configuracionSmtp($this->company));

        $this->assertInstanceOf(EsmtpTransport::class, $transporte);
        $this->assertSame('facturas@andina.test', $transporte->getUsername());
        $this->assertSame(self::CLAVE_SMTP, $transporte->getPassword());
        $this->assertSame('smtp.andina.test', $transporte->getStream()->getHost());
        $this->assertSame(587, $transporte->getStream()->getPort());
    }

    // ------------------------------------------------------------ nunca rompe: la falla del correo queda en la bitácora

    public function test_si_el_servidor_de_correo_falla_se_registra_el_error_sin_ningun_secreto(): void
    {
        $this->servidorQueFalla();
        Log::spy();

        $r = $this->servicio()->enviar($this->factura(), ['maria@cliente.test']);

        $this->assertSame('error', $r->estado);
        $this->assertStringContainsString('rechazó el usuario o la clave', $r->mensaje);
        $log = EmailLog::firstOrFail();
        $this->assertSame('error', $log->estado);
        $this->assertNull($log->sent_at);
        $this->assertLessThanOrEqual(500, mb_strlen($log->motivo));
        // Ni la clave, ni su versión en base64, en ningún lado: bitácora, respuesta ni logs de Laravel
        foreach ([self::CLAVE_SMTP, base64_encode(self::CLAVE_SMTP)] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString($secreto, $r->mensaje);
            Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (...$args) use ($secreto) {
                $this->assertStringNotContainsString($secreto, json_encode($args, JSON_UNESCAPED_UNICODE));

                return true;
            });
        }
    }

    public function test_el_servidor_inalcanzable_se_explica_en_espanol_y_el_motivo_se_recorta(): void
    {
        $largo = 'Connection could not be established with host "smtp.andina.test:587": '.str_repeat('detalle técnico muy largo ', 80);
        $this->servidorQueFalla($largo);

        $r = $this->servicio()->enviar($this->factura(), ['maria@cliente.test']);

        $this->assertSame('error', $r->estado);
        $this->assertStringContainsString('No se pudo conectar con el servidor de correo (smtp.andina.test:587)', $r->mensaje);
        $log = EmailLog::firstOrFail();
        $this->assertLessThanOrEqual(500, mb_strlen($log->motivo));
        $this->assertStringContainsString('No se pudo conectar', $log->motivo);
    }

    public function test_si_falla_el_armado_del_ride_el_correo_no_sale_y_nada_se_propaga(): void
    {
        $factura = $this->factura();
        $this->app->bind(RideBuilder::class, fn ($app) => new class($app->make(\App\Services\DocumentCalculator::class)) extends RideBuilder {
            public function paraFactura(\App\Models\Invoice $f): array
            {
                throw new \RuntimeException('falla del RIDE con '.CorreoTestCase::class.' '.'Cl4ve-SMTP-Ultra-Secreta-7741');
            }
        });

        $r = $this->servicio()->enviar($factura, ['maria@cliente.test']);

        $this->assertSame('error', $r->estado);
        $this->assertStringContainsString('No se pudo generar el RIDE', $r->mensaje);
        $this->assertCount(0, $this->transporte->messages());
        $log = EmailLog::firstOrFail();
        $this->assertSame('error', $log->estado);
        $this->assertSame('AUTORIZADO', $factura->sriDocument()->firstOrFail()->estado, 'El comprobante queda intacto');
    }

    public function test_una_falla_imprevista_antes_de_armar_el_correo_tampoco_se_propaga(): void
    {
        // Una factura cuya empresa no existe: ni siquiera se puede preparar el envío
        $huerfana = new \App\Models\Invoice(['company_id' => 987654, 'numero' => '001-001-000000777', 'contact_id' => $this->cliente->id]);

        $r = $this->servicio()->enviar($huerfana, ['maria@cliente.test']);

        $this->assertSame('error', $r->estado);
        $this->assertStringContainsString('No se pudo preparar el correo', $r->mensaje);
        $this->assertCount(0, $this->transporte->messages());
    }

    // ------------------------------------------------------------ omitido

    public function test_sin_correo_del_cliente_se_omite_y_se_deja_constancia(): void
    {
        $this->cliente->update(['email' => null, 'email2' => null]);
        $factura = $this->factura();

        $r = $this->servicio()->enviar($factura, $this->servicio()->destinatariosDe($factura));

        $this->assertSame('omitido', $r->estado);
        $this->assertStringContainsString('no tiene un correo electrónico', $r->mensaje);
        $this->assertCount(0, $this->transporte->messages());
        $log = EmailLog::firstOrFail();
        $this->assertSame('omitido', $log->estado);
        $this->assertSame([], $log->destinatarios);
        $this->assertFalse($log->automatico);
    }

    public function test_sin_servidor_de_correo_configurado_se_omite(): void
    {
        $this->company->update(['smtp_host' => null]);
        $r = $this->servicio()->enviar($this->factura(), ['maria@cliente.test']);
        $this->assertSame('omitido', $r->estado);
        $this->assertStringContainsString('no tiene configurado el servidor de correo', $r->mensaje);

        $this->company->update(['smtp_host' => 'smtp.andina.test', 'smtp_password' => null]);
        $r = $this->servicio()->enviar($this->factura(), ['maria@cliente.test']);
        $this->assertSame('omitido', $r->estado, 'Sin la clave tampoco hay con qué entrar al servidor');

        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(['omitido', 'omitido'], EmailLog::orderBy('id')->pluck('estado')->all());
    }

    public function test_el_envio_automatico_apagado_se_omite_pero_el_manual_no_depende_de_ese_interruptor(): void
    {
        $this->assertFalse($this->company->fresh()->enviar_comprobantes_automaticamente, 'Apagado por defecto');
        $factura = $this->factura();

        $auto = $this->servicio()->enviar($factura, ['maria@cliente.test'], true);
        $this->assertSame('omitido', $auto->estado);
        $this->assertStringContainsString('desactivado', $auto->mensaje);
        $this->assertCount(0, $this->transporte->messages());
        $this->assertTrue(EmailLog::firstOrFail()->automatico);

        $manual = $this->servicio()->enviar($factura, ['maria@cliente.test'], false);
        $this->assertSame('enviado', $manual->estado);
        $this->assertCount(1, $this->transporte->messages());
    }

    public function test_un_documento_sin_comprobante_electronico_o_una_nota_interna_no_se_envian(): void
    {
        $sinComprobante = $this->factura();
        $sinComprobante->sriDocument()->delete();
        $r = $this->servicio()->enviar($sinComprobante->fresh(), ['maria@cliente.test']);
        $this->assertSame('omitido', $r->estado);

        $interna = $this->notaCredito('AUTORIZADO', 'interna');
        $this->assertNull(EnviarComprobanteCorreo::tipoDe(new \App\Models\Withholding(['tipo' => 'recibida'])));
        $this->assertTrue(EnviarComprobanteCorreo::esInterna($interna));
        $this->assertNull($this->servicio()->alAutorizarse(new SriDocument(['estado' => 'AUTORIZADO'])));
        $this->assertCount(0, $this->transporte->messages());
    }

    public function test_los_destinatarios_se_limpian_sin_repetir_ni_aceptar_invalidos(): void
    {
        $r = $this->servicio()->enviar($this->factura(), [' Maria@Cliente.test ', 'maria@cliente.test', 'no-es-correo', '', 'otro@x.test']);

        $this->assertSame(['Maria@Cliente.test', 'otro@x.test'], $r->destinatarios);
        $this->assertSame(['Maria@Cliente.test', 'otro@x.test'], $this->aDirecciones($this->correo()->getTo()));
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $this->servicio()->destinatariosDe($this->factura()));
    }

    // ------------------------------------------------------------ bitácora

    public function test_cada_envio_deja_su_fila_con_el_asunto_los_adjuntos_y_quien_lo_hizo(): void
    {
        $factura = $this->factura();
        $usuario = $this->actuarComo('contador');

        $this->servicio()->enviar($factura, ['maria@cliente.test'], false, $usuario->id);

        $log = EmailLog::firstOrFail();
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame($factura->getMorphClass(), $log->documentable_type);
        $this->assertSame($factura->id, $log->documentable_id);
        $this->assertSame('factura', $log->tipo_comprobante);
        $this->assertSame(['maria@cliente.test'], $log->destinatarios);
        $this->assertSame('Factura 001-001-000000001 de Distribuidora Andina SA', $log->asunto);
        $this->assertSame('enviado', $log->estado);
        $this->assertNull($log->motivo);
        $this->assertFalse($log->automatico);
        $this->assertSame($usuario->id, $log->user_id);
        $this->assertNotNull($log->sent_at);
        $this->assertSame(['RIDE-001-001-000000001.pdf', $factura->sriDocument()->firstOrFail()->clave_acceso.'.xml'], array_column($log->adjuntos, 'nombre'));
        $this->assertSame(['application/pdf', 'application/xml'], array_column($log->adjuntos, 'tipo'));
        $this->assertGreaterThan(0, $log->adjuntos[0]['bytes']);
    }

    public function test_el_estado_del_correo_de_muchos_documentos_sale_con_una_sola_consulta(): void
    {
        $facturas = collect([$this->factura(), $this->factura(), $this->factura(), $this->factura()]);
        $this->servicio()->enviar($facturas[0], ['maria@cliente.test']);
        $this->servidorQueFalla();
        $this->servicio()->enviar($facturas[1], ['maria@cliente.test']);
        $this->servicio()->enviar($facturas[1], ['maria@cliente.test']);

        DB::enableQueryLog();
        $resumen = EmailLog::resumenDe($facturas);
        $this->assertCount(1, DB::getQueryLog(), 'Una sola consulta para toda la lista');

        $clave = fn ($f) => $f->getMorphClass().':'.$f->id;
        $this->assertSame('enviado', $resumen[$clave($facturas[0])]['estado']);
        $this->assertSame(['maria@cliente.test'], $resumen[$clave($facturas[0])]['destinatarios']);
        $this->assertSame('error', $resumen[$clave($facturas[1])]['estado']);
        $this->assertStringContainsString('rechazó', $resumen[$clave($facturas[1])]['motivo']);
        $this->assertSame('sin_enviar', $resumen[$clave($facturas[2])]['estado']);
    }

    // ------------------------------------------------------------ el XML autorizado y el texto seguro

    public function test_el_xml_autorizado_conserva_el_comprobante_aunque_traiga_un_cierre_de_cdata(): void
    {
        $factura = $this->factura();
        $sri = $factura->sriDocument()->firstOrFail();
        $raro = '<?xml version="1.0"?><factura><infoAdicional><campoAdicional nombre="x"><![CDATA[a ]]> b]]></campoAdicional></infoAdicional></factura>';
        $sri->update(['xml_firmado' => $raro, 'mensajes' => ['RespuestaAutorizacionComprobante' => ['autorizaciones' => ['autorizacion' => [
            'estado' => 'AUTORIZADO', 'numeroAutorizacion' => '123', 'fechaAutorizacion' => '2026-10-07T10:00:00-05:00', 'comprobante' => $raro]]]]]);

        $xml = simplexml_load_string($sri->fresh()->xmlAutorizado());

        $this->assertNotFalse($xml);
        $this->assertSame($raro, (string) $xml->comprobante, 'El comprobante sale idéntico dentro del CDATA');
    }

    public function test_solo_un_comprobante_autorizado_tiene_xml_autorizado_y_el_importado_sale_tal_cual(): void
    {
        $this->assertNull($this->factura('firmado')->sriDocument()->firstOrFail()->xmlAutorizado());

        $importado = $this->factura('AUTORIZADO');
        $original = '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>999</numeroAutorizacion><comprobante><![CDATA[<factura/>]]></comprobante></autorizacion>';
        $importado->sriDocument()->firstOrFail()->update(['xml' => $original, 'xml_firmado' => null, 'mensajes' => ['importado' => 'xml_ventas_emitidas']]);
        $this->assertSame($original, $importado->sriDocument()->firstOrFail()->xmlAutorizado());
    }

    public function test_el_texto_seguro_quita_secretos_urls_con_clave_y_recorta(): void
    {
        $t = TextoSeguro::limpiar("Falló con clave Abc123!xyz\nen smtp://usuario:Abc123%21xyz@mail.test:587 y base64 ".base64_encode('Abc123!xyz'), ['Abc123!xyz']);
        $this->assertStringNotContainsString('Abc123', $t);
        $this->assertStringNotContainsString(base64_encode('Abc123!xyz'), $t);
        $this->assertStringContainsString('smtp://usuario:***@mail.test:587', $t);
        $this->assertStringNotContainsString("\n", $t);

        $largo = TextoSeguro::limpiar(str_repeat('á', 1000), [], 100);
        $this->assertSame(100, mb_strlen($largo));
        $this->assertStringEndsWith('…', $largo);
    }

    public function test_el_mailable_expone_los_nombres_de_sus_adjuntos(): void
    {
        $correo = new ComprobanteMail('a', new \Illuminate\Mail\Mailables\Address('a@b.test'), null, [],
            [['nombre' => 'x.pdf', 'mime' => 'application/pdf', 'datos' => '%PDF'], ['nombre' => 'x.xml', 'mime' => 'application/xml', 'datos' => '<a/>']]);

        $this->assertSame(['x.pdf', 'x.xml'], $correo->nombresDeAdjuntos());
        $this->assertCount(2, $correo->attachments());
    }
}
