<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\SriDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LibreriasSri\FacturacionElectronicaLibrary;
use Tests\TestCase;

/**
 * T3.6a: "Autorizar todo" de verdad reenvía. Antes solo volvía a consultar la autorización: un comprobante que se firmó
 * pero nunca salió ("firmado") se quedaba así para siempre. Ahora, por cada comprobante pendiente corre en orden los
 * pasos que le faltan (firmar si ya hay certificado → enviar → autorizar) y devuelve el resultado de cada uno
 * (autorizado / pendiente / error + mensaje). Las llamadas al SRI se reemplazan por una librería falsa.
 */
class AutorizarTodoSriTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private object $sri;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->empresa(conCertificado: true);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        $this->sri = new class extends FacturacionElectronicaLibrary {
            /** @var array<int, array> cada llamada al SRI, en orden: [paso, ...argumentos] */
            public array $llamadas = [];
            /** @var array<string, mixed> respuesta de recepción por XML firmado (si no hay, RECIBIDA) */
            public array $recepcion = [];
            /** @var array<string, mixed> respuesta de autorización por clave de acceso (si no hay, pendiente) */
            public array $autorizacion = [];
            /** @var array<string, string> mensaje de excepción por XML firmado al enviar */
            public array $falloEnvio = [];

            public function __construct() {}

            public function firmarXml($tipoComprobante, $xml, $certificadoP12, $claveCertificado)
            {
                $this->llamadas[] = ['firmar', $tipoComprobante, $xml];

                return '<firmado>'.$xml.'</firmado>';
            }

            public function enviarSri($xmlFirmado, $ambiente)
            {
                $this->llamadas[] = ['enviar', $xmlFirmado, (string) $ambiente];
                if (isset($this->falloEnvio[$xmlFirmado])) {
                    throw new \RuntimeException($this->falloEnvio[$xmlFirmado]);
                }

                return $this->recepcion[$xmlFirmado] ?? ['RespuestaRecepcionComprobante' => ['estado' => 'RECIBIDA', 'comprobantes' => '']];
            }

            public function autorizarSri($claveAcceso, $ambiente)
            {
                $this->llamadas[] = ['autorizar', $claveAcceso, (string) $ambiente];

                return $this->autorizacion[$claveAcceso] ?? ['RespuestaAutorizacionComprobante' => [
                    'claveAccesoConsultada' => $claveAcceso, 'numeroComprobantes' => '0', 'autorizaciones' => '',
                ]];
            }

            /** Pasos ('firmar', 'enviar', 'autorizar') en el orden en que ocurrieron */
            public function pasos(): array
            {
                return array_column($this->llamadas, 0);
            }
        };
        $this->app->instance(FacturacionElectronicaLibrary::class, $this->sri);
        Sanctum::actingAs(User::factory()->create());
    }

    private function empresa(bool $conCertificado, string $ruc = '1791234567001'): Company
    {
        return Company::create([
            'ruc' => $ruc, 'razon_social' => 'Empresa SRI Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'ambiente' => 1,
            'certificado_p12' => $conCertificado ? 'p12-de-prueba' : null,
            'certificado_clave' => $conCertificado ? 'clave-de-prueba' : null,
        ]);
    }

    /** Una factura con su comprobante electrónico en el estado dado. */
    private function documento(string $estado, ?Company $empresa = null, array $extra = []): SriDocument
    {
        $empresa ??= $this->company;
        $this->n++;
        $factura = Invoice::create([
            'company_id' => $empresa->id, 'contact_id' => $this->cliente->id, 'numero' => sprintf('001-001-%09d', $this->n), 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'forma_pago' => 'credito',
            'saldo_pendiente' => 115, 'estado' => 'emitida', 'fecha_emision' => now(),
        ]);

        return SriDocument::create($extra + [
            'company_id' => $empresa->id, 'documentable_type' => $factura->getMorphClass(), 'documentable_id' => $factura->id,
            'tipo_comprobante' => 'factura', 'clave_acceso' => str_pad((string) (2610202601 * 1000 + $this->n), 49, '0', STR_PAD_LEFT),
            'xml' => '<factura n="'.$this->n.'"/>',
            'xml_firmado' => in_array($estado, ['firmado', 'enviado'], true) ? '<xml-firmado n="'.$this->n.'"/>' : null,
            'estado' => $estado, 'ambiente' => 1, 'fecha_emision' => now(),
        ]);
    }

    private function respuestaAutorizado(SriDocument $doc, string $numero): array
    {
        return ['RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $doc->clave_acceso, 'numeroComprobantes' => '1',
            'autorizaciones' => ['autorizacion' => [
                'estado' => 'AUTORIZADO', 'numeroAutorizacion' => $numero, 'fechaAutorizacion' => '2026-10-07T10:00:00-05:00',
                'ambiente' => 'PRUEBAS', 'comprobante' => '<factura/>', 'mensajes' => '',
            ]],
        ]];
    }

    private function lote(array $extra = [])
    {
        return $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id] + $extra);
    }

    // ------------------------------------------------------------ el caso del bug: firmado y nunca enviado

    public function test_un_comprobante_firmado_que_nunca_salio_se_envia_y_luego_se_autoriza(): void
    {
        $doc = $this->documento('firmado');
        $this->sri->autorizacion[$doc->clave_acceso] = $this->respuestaAutorizado($doc, '2610202601179123456700110010010000000011234567813');

        $r = $this->lote()->assertOk();

        $this->assertSame(['enviar', 'autorizar'], $this->sri->pasos(), 'Antes solo consultaba la autorización y nunca lo enviaba');
        $this->assertSame('<xml-firmado n="1"/>', $this->sri->llamadas[0][1], 'Se envía el XML ya firmado');
        $this->assertSame('1', $this->sri->llamadas[0][2], 'Va al ambiente con que se generó el comprobante');
        $this->assertSame($doc->clave_acceso, $this->sri->llamadas[1][1]);

        $doc->refresh();
        $this->assertSame('AUTORIZADO', $doc->estado);
        $this->assertSame('2610202601179123456700110010010000000011234567813', $doc->numero_autorizacion);

        $this->assertSame(1, $r->json('procesados'));
        $this->assertSame(1, $r->json('autorizados'));
        $this->assertSame(0, $r->json('pendientes'));
        $this->assertSame(0, $r->json('fallidos'));
        $fila = $r->json('resultados.0');
        $this->assertSame($doc->id, $fila['id']);
        $this->assertSame('autorizado', $fila['resultado']);
        $this->assertSame('AUTORIZADO', $fila['estado']);
        $this->assertSame('001-001-000000001', $fila['numero']);
    }

    public function test_un_comprobante_ya_enviado_solo_consulta_la_autorizacion_y_no_se_reenvia(): void
    {
        $doc = $this->documento('enviado');
        $this->sri->autorizacion[$doc->clave_acceso] = $this->respuestaAutorizado($doc, 'AUT-ENVIADO');

        $this->lote()->assertOk();

        $this->assertSame(['autorizar'], $this->sri->pasos(), 'Ya está en el SRI: reenviarlo daría "clave de acceso registrada"');
        $this->assertSame('AUTORIZADO', $doc->fresh()->estado);
        $this->assertSame('AUT-ENVIADO', $doc->fresh()->numero_autorizacion);
    }

    public function test_un_comprobante_generado_se_firma_envia_y_autoriza_cuando_la_empresa_ya_tiene_certificado(): void
    {
        $doc = $this->documento('generado');
        $this->sri->autorizacion[$doc->clave_acceso] = $this->respuestaAutorizado($doc, 'AUT-GENERADO');

        $r = $this->lote()->assertOk();

        $this->assertSame(['firmar', 'enviar', 'autorizar'], $this->sri->pasos());
        $this->assertSame('factura', $this->sri->llamadas[0][1]);
        $this->assertSame('<firmado><factura n="1"/></firmado>', $doc->fresh()->xml_firmado);
        $this->assertSame('AUTORIZADO', $doc->fresh()->estado);
        $this->assertSame('autorizado', $r->json('resultados.0.resultado'));
    }

    // ------------------------------------------------------------ sin certificado: mensaje claro, nada falso

    public function test_sin_certificado_el_comprobante_sigue_generado_con_un_mensaje_claro_y_sin_llamar_al_sri(): void
    {
        $sinCert = $this->empresa(conCertificado: false, ruc: '1790000000001');
        $doc = $this->documento('generado', $sinCert);

        $r = $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $sinCert->id])->assertOk();

        $this->assertSame([], $this->sri->llamadas, 'Sin certificado no se llama al SRI');
        $doc->refresh();
        $this->assertSame('generado', $doc->estado, 'No se inventa ningún estado');
        $this->assertNull($doc->xml_firmado);
        $this->assertNull($doc->numero_autorizacion);

        $this->assertSame(1, $r->json('procesados'));
        $this->assertSame(0, $r->json('autorizados'));
        $this->assertSame(1, $r->json('fallidos'));
        $this->assertSame(1, $r->json('sin_firma'));
        $this->assertStringContainsString('certificado .p12', $r->json('mensaje'));
        $fila = $r->json('resultados.0');
        $this->assertSame('error', $fila['resultado']);
        $this->assertSame('generado', $fila['estado']);
        $this->assertStringContainsString('certificado', $fila['mensaje']);
    }

    // ------------------------------------------------------------ pendiente y rechazos

    public function test_si_el_sri_todavia_no_responde_la_autorizacion_queda_pendiente_como_enviado(): void
    {
        $doc = $this->documento('firmado');   // sin respuesta de autorización configurada: el SRI aún no responde

        $r = $this->lote()->assertOk();

        $this->assertSame(['enviar', 'autorizar'], $this->sri->pasos());
        $this->assertSame('enviado', $doc->fresh()->estado, 'Salió, pero todavía no está autorizado');
        $this->assertSame(0, $r->json('autorizados'));
        $this->assertSame(1, $r->json('pendientes'));
        $this->assertSame(0, $r->json('fallidos'));
        $this->assertSame('pendiente', $r->json('resultados.0.resultado'));
        $this->assertSame('enviado', $r->json('resultados.0.estado'));
    }

    public function test_si_el_sri_no_autoriza_se_guarda_el_estado_y_el_motivo(): void
    {
        $doc = $this->documento('enviado');
        $this->sri->autorizacion[$doc->clave_acceso] = ['RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $doc->clave_acceso, 'numeroComprobantes' => '1',
            'autorizaciones' => ['autorizacion' => [
                'estado' => 'NO AUTORIZADO', 'numeroAutorizacion' => '', 'ambiente' => 'PRUEBAS',
                'mensajes' => ['mensaje' => ['identificador' => '39', 'mensaje' => 'FIRMA INVALIDA', 'informacionAdicional' => 'La firma del comprobante no es válida', 'tipo' => 'ERROR']],
            ]],
        ]];

        $r = $this->lote()->assertOk();

        $this->assertSame('NO AUTORIZADO', $doc->fresh()->estado);
        $this->assertSame(1, $r->json('fallidos'));
        $fila = $r->json('resultados.0');
        $this->assertSame('error', $fila['resultado']);
        $this->assertStringContainsString('FIRMA INVALIDA', $fila['mensaje']);
    }

    public function test_un_comprobante_que_el_sri_ya_rechazo_no_se_vuelve_a_enviar(): void
    {
        $this->documento('NO AUTORIZADO', null, ['xml_firmado' => '<xml-firmado/>']);

        $r = $this->lote()->assertOk();

        $this->assertSame([], $this->sri->llamadas, 'Un rechazo del SRI es definitivo para esa clave de acceso');
        $this->assertSame('error', $r->json('resultados.0.resultado'));
        $this->assertSame('NO AUTORIZADO', $r->json('resultados.0.estado'));
    }

    public function test_si_la_recepcion_devuelve_el_comprobante_queda_firmado_con_el_motivo_y_no_se_consulta_la_autorizacion(): void
    {
        $doc = $this->documento('firmado');
        $this->sri->recepcion[$doc->xml_firmado] = ['RespuestaRecepcionComprobante' => [
            'estado' => 'DEVUELTA',
            'comprobantes' => ['comprobante' => ['claveAcceso' => $doc->clave_acceso, 'mensajes' => ['mensaje' => [
                'identificador' => '35', 'mensaje' => 'ARCHIVO NO CUMPLE ESTRUCTURA XML', 'informacionAdicional' => 'Falta el campo dirMatriz', 'tipo' => 'ERROR',
            ]]]],
        ]];

        $r = $this->lote()->assertOk();

        $this->assertSame(['enviar'], $this->sri->pasos(), 'Si el SRI no lo recibió no hay nada que autorizar');
        $this->assertSame('firmado', $doc->fresh()->estado);
        $fila = $r->json('resultados.0');
        $this->assertSame('error', $fila['resultado']);
        $this->assertSame('firmado', $fila['estado']);
        $this->assertStringContainsString('ARCHIVO NO CUMPLE ESTRUCTURA XML', $fila['mensaje']);
        $this->assertStringContainsString('error_enviar', json_encode($doc->fresh()->mensajes));
    }

    public function test_si_el_sri_dice_que_la_clave_ya_esta_registrada_se_sigue_con_la_autorizacion(): void
    {
        $doc = $this->documento('firmado');
        $this->sri->recepcion[$doc->xml_firmado] = ['RespuestaRecepcionComprobante' => [
            'estado' => 'DEVUELTA',
            'comprobantes' => ['comprobante' => ['claveAcceso' => $doc->clave_acceso, 'mensajes' => ['mensaje' => [
                'identificador' => '43', 'mensaje' => 'CLAVE ACCESO REGISTRADA', 'tipo' => 'ERROR',
            ]]]],
        ]];
        $this->sri->autorizacion[$doc->clave_acceso] = $this->respuestaAutorizado($doc, 'AUT-43');

        $this->lote()->assertOk();

        $this->assertSame(['enviar', 'autorizar'], $this->sri->pasos());
        $this->assertSame('AUTORIZADO', $doc->fresh()->estado);
    }

    public function test_un_fallo_de_conexion_al_enviar_no_detiene_a_los_demas(): void
    {
        $falla = $this->documento('firmado');
        $bueno = $this->documento('firmado');
        $this->sri->falloEnvio[$falla->xml_firmado] = 'Tiempo de espera agotado con el SRI';
        $this->sri->autorizacion[$bueno->clave_acceso] = $this->respuestaAutorizado($bueno, 'AUT-BUENO');

        $r = $this->lote()->assertOk();

        $this->assertSame('firmado', $falla->fresh()->estado);
        $this->assertSame('AUTORIZADO', $bueno->fresh()->estado);
        $this->assertSame(2, $r->json('procesados'));
        $this->assertSame(1, $r->json('autorizados'));
        $this->assertSame(1, $r->json('fallidos'));
        $porId = collect($r->json('resultados'))->keyBy('id');
        $this->assertSame('error', $porId[$falla->id]['resultado']);
        $this->assertStringContainsString('Tiempo de espera agotado', $porId[$falla->id]['mensaje']);
        $this->assertSame('autorizado', $porId[$bueno->id]['resultado']);
    }

    public function test_acepta_tambien_la_respuesta_de_autorizacion_plana_de_siempre(): void
    {
        $doc = $this->documento('enviado');
        $this->sri->autorizacion[$doc->clave_acceso] = ['estado' => 'AUTORIZADO', 'numeroAutorizacion' => 'AUT-PLANA'];

        $this->lote()->assertOk();

        $this->assertSame('AUTORIZADO', $doc->fresh()->estado);
        $this->assertSame('AUT-PLANA', $doc->fresh()->numero_autorizacion);
    }

    // ------------------------------------------------------------ selección y alcance

    public function test_con_ids_solo_procesa_los_seleccionados(): void
    {
        $a = $this->documento('firmado');
        $b = $this->documento('firmado');
        $c = $this->documento('firmado');
        $this->sri->autorizacion[$a->clave_acceso] = $this->respuestaAutorizado($a, 'AUT-A');
        $this->sri->autorizacion[$c->clave_acceso] = $this->respuestaAutorizado($c, 'AUT-C');

        $r = $this->lote(['ids' => [$a->id, $c->id]])->assertOk();

        $this->assertSame(2, $r->json('procesados'));
        $this->assertSame('AUTORIZADO', $a->fresh()->estado);
        $this->assertSame('firmado', $b->fresh()->estado, 'El no seleccionado no se toca');
        $this->assertSame('AUTORIZADO', $c->fresh()->estado);
        $this->assertSame([$a->id, $c->id], array_column($r->json('resultados'), 'id'));
    }

    public function test_no_toca_comprobantes_autorizados_ni_de_otra_empresa(): void
    {
        $otra = $this->empresa(conCertificado: true, ruc: '1790000000001');
        $ajeno = $this->documento('firmado', $otra);
        $ya = $this->documento('AUTORIZADO', null, ['xml_firmado' => '<x/>']);
        $pendiente = $this->documento('enviado');
        $this->sri->autorizacion[$pendiente->clave_acceso] = $this->respuestaAutorizado($pendiente, 'AUT-P');

        $r = $this->lote(['ids' => [$ajeno->id, $ya->id, $pendiente->id]])->assertOk();

        $this->assertSame(1, $r->json('procesados'));
        $this->assertSame('firmado', $ajeno->fresh()->estado, 'Un comprobante de otra empresa no se procesa aunque venga en ids');
        $this->assertSame([$pendiente->id], array_column($r->json('resultados'), 'id'));
    }

    public function test_un_comprobante_de_un_documento_anulado_no_se_envia_ni_figura_como_pendiente(): void
    {
        $anulado = $this->documento('firmado');
        Invoice::whereKey($anulado->documentable_id)->update(['estado' => 'anulado']);
        $vigente = $this->documento('firmado');
        $this->sri->autorizacion[$vigente->clave_acceso] = $this->respuestaAutorizado($vigente, 'AUT-VIGENTE');

        $lista = $this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->assertOk()->json();
        $this->assertSame([$vigente->id], array_column($lista, 'id'), 'Lo anulado no es "por autorizar"');

        $r = $this->lote(['ids' => [$anulado->id, $vigente->id]])->assertOk();

        $this->assertSame(1, $r->json('procesados'));
        $this->assertSame('firmado', $anulado->fresh()->estado, 'Mandarlo al SRI lo dejaría válido allá estando anulado aquí');
        $this->assertSame(['enviar', 'autorizar'], $this->sri->pasos(), 'Solo salió el vigente');
        $this->assertSame('AUTORIZADO', $vigente->fresh()->estado);
    }

    public function test_sin_pendientes_no_hace_nada(): void
    {
        $this->documento('AUTORIZADO', null, ['xml_firmado' => '<x/>']);

        $r = $this->lote()->assertOk();

        $this->assertSame(0, $r->json('procesados'));
        $this->assertSame([], $r->json('resultados'));
        $this->assertSame([], $this->sri->llamadas);
    }

    public function test_el_listado_de_pendientes_trae_el_numero_y_el_ultimo_mensaje(): void
    {
        $doc = $this->documento('firmado', null, ['mensajes' => ['error_enviar' => 'Tiempo de espera agotado']]);
        $this->documento('AUTORIZADO', null, ['xml_firmado' => '<x/>']);

        $lista = $this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->assertOk()->json();

        $this->assertCount(1, $lista);
        $this->assertSame($doc->id, $lista[0]['id']);
        $this->assertSame('firmado', $lista[0]['estado']);
        $this->assertSame('001-001-000000001', $lista[0]['numero']);
        $this->assertStringContainsString('Tiempo de espera agotado', $lista[0]['detalle']);
    }
}
