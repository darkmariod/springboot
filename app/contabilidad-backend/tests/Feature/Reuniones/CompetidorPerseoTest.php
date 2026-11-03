<?php

namespace Tests\Feature\Reuniones;

use App\Models\EmailLog;
use PHPUnit\Framework\Attributes\Group;

/**
 * Lo que ofrece el competidor Perseo y HasReset debe igualar: enviar el comprobante electrónico al cliente apenas el SRI lo autoriza.
 * Nada sale a la red: el servidor de correo y el SRI son simulados.
 */
#[Group('reuniones')]
class CompetidorPerseoTest extends ReunionesTestCase
{
    /** La empresa enciende el envío automático desde su configuración, como lo haría el administrador. */
    private function encenderEnvioAutomatico(): void
    {
        $this->postJson("/api/companies/{$this->company->id}/edoc-config", ['enviar_comprobantes_automaticamente' => true])->assertOk();
        $this->assertTrue((bool) $this->company->fresh()->enviar_comprobantes_automaticamente);
    }

    /**
     * Pedido por: COMP
     * Pedido: «Perseo manda la factura sola al correo del cliente cuando el SRI la autoriza, con el PDF y el XML.»
     * Se prueba: al autorizarse la factura sale UN correo al cliente con el RIDE en PDF y el XML autorizado, y no se repite al volver a consultar.
     */
    #[Group('reuniones')]
    #[Group('R22')]
    public function test_R22_al_autorizarse_el_comprobante_el_cliente_recibe_el_RIDE_y_el_XML_una_sola_vez(): void
    {
        // Arrange: la empresa tiene certificado, el SRI (simulado) autoriza y el envío automático está encendido
        $this->conComprobanteReal();
        $this->conCertificado();
        $this->sriFalso(true);
        $this->encenderEnvioAutomatico();

        // Act: se emite la factura
        $factura = $this->facturar($this->cliente, 100, 15, 'efectivo');

        // Assert: la factura quedó autorizada y salió exactamente un correo
        $sri = $factura->sriDocument()->firstOrFail();
        $this->assertSame('AUTORIZADO', $sri->estado);
        $this->assertCount(1, $this->transporte->messages());
        $correo = $this->correo();
        $this->assertSame('Factura '.$factura->numero.' de Distribuidora Andina SA', $correo->getSubject());
        $this->assertSame(['maria@cliente.test', 'contabilidad@cliente.test'], $this->aDirecciones($correo->getTo()));
        $adjuntos = $this->adjuntos($correo);
        $this->assertSame(['RIDE-'.$factura->numero.'.pdf', $sri->clave_acceso.'.xml'], array_keys($adjuntos));
        $this->assertStringStartsWith('%PDF', $adjuntos['RIDE-'.$factura->numero.'.pdf']->getBody());
        $this->assertSame('autorizacion', simplexml_load_string($adjuntos[$sri->clave_acceso.'.xml']->getBody())->getName());
        $this->assertSame('enviado', EmailLog::firstOrFail()->estado);

        // Assert: volver a autorizar todo no repite el correo
        $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('procesados', 0);
        $this->assertCount(1, $this->transporte->messages());
        $this->assertSame(1, EmailLog::count());
    }

    /**
     * Pedido por: COMP
     * Pedido: «Y si todavía no hay certificado, que no mande nada: no se puede mandar un comprobante que el SRI no autorizó.»
     * Se prueba: con el envío encendido pero sin certificado la factura queda «generada» y no sale ningún correo ni intento en la bitácora.
     */
    #[Group('reuniones')]
    #[Group('R22')]
    public function test_R22_sin_certificado_no_se_envia_nada_automatico(): void
    {
        // Arrange: sin certificado, aunque el SRI autorizara todo
        $this->conComprobanteReal();
        $this->sriFalso(true);
        $this->encenderEnvioAutomatico();
        $this->assertNull($this->company->fresh()->certificado_p12);

        // Act
        $factura = $this->facturar($this->cliente, 100, 15, 'efectivo');

        // Assert
        $this->assertSame('generado', $factura->sriDocument()->firstOrFail()->estado);
        $this->assertCount(0, $this->transporte->messages());
        $this->assertSame(0, EmailLog::count(), 'Ni un intento: no hay autorización');
    }
}
