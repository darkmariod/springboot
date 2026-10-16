<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Withholding;
use App\Services\EnviarComprobanteCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Laravel\Sanctum\Sanctum;
use LibreriasSri\FacturacionElectronicaLibrary;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Base de las pruebas del envío de comprobantes por correo (T10). NADA sale a la red: el transporte "smtp" se reemplaza por
 * uno en memoria (o por uno que falla) con Mail::extend, así que se prueba el camino real (configuración de la empresa → mailer
 * propio → mensaje con adjuntos) sin abrir una sola conexión. La librería del SRI también es falsa.
 */
abstract class CorreoTestCase extends TestCase
{
    use RefreshDatabase;

    /** Una clave SMTP reconocible: si aparece en una bitácora, un log o una respuesta, la prueba lo atrapa. */
    protected const CLAVE_SMTP = 'Cl4ve-SMTP-Ultra-Secreta-7741';

    protected Company $company;
    protected Contact $cliente;
    protected Contact $proveedor;
    protected ArrayTransport $transporte;
    /** @var \stdClass configs: la configuración con que Laravel armó cada transporte SMTP */
    protected \stdClass $capt;
    protected int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // dompdf carga las tablas de las fuentes: con la suite completa en un solo proceso hace falta más memoria
        $limite = trim((string) ini_get('memory_limit'));
        $bytes = $limite === '-1' ? PHP_INT_MAX : (int) $limite * match (strtoupper(substr($limite, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
        if ($bytes < 512 * 1048576) {
            ini_set('memory_limit', '512M');
        }

        EnviarComprobanteCorreo::reiniciar();
        $this->usarTransporte($this->transporte = new ArrayTransport());

        $this->company = $this->empresa();
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001',
            'razon_social' => 'María Fernanda Pazmiño', 'direccion' => 'Calle Los Pinos 45', 'telefono' => '0999999999',
            'email' => 'maria@cliente.test', 'email2' => 'contabilidad@cliente.test',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001',
            'razon_social' => 'Proveedor Test SA', 'email' => 'prov@proveedor.test',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $this->actuarComo('admin');
    }

    // ------------------------------------------------------------ empresa y usuario

    protected function empresa(array $extra = []): Company
    {
        static $ruc = 1791234567000;
        $ruc++;

        return Company::create($extra + [
            'ruc' => (string) $ruc, 'razon_social' => 'Distribuidora Andina SA', 'nombre_comercial' => 'Andina Express',
            'dir_matriz' => 'Av. Amazonas N24-15 y Colón, Quito', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
            'ambiente' => 1, 'obligado_contabilidad' => true, 'telefonos' => '022555111',
            'email_envio' => 'facturas@andina.test',
            'smtp_host' => 'smtp.andina.test', 'smtp_port' => 587, 'smtp_user' => 'facturas@andina.test',
            'smtp_password' => self::CLAVE_SMTP, 'smtp_ssl' => true,
        ]);
    }

    protected function actuarComo(string $rol, ?Company $empresa = null): User
    {
        $usuario = User::factory()->create(['rol' => $rol, 'activo' => true, 'company_id' => $empresa?->id]);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    // ------------------------------------------------------------ el transporte de correo

    /** Todo lo que Laravel intente enviar por SMTP se entrega a este transporte (nunca a la red). */
    protected function usarTransporte(TransportInterface $transporte): void
    {
        $this->capt = $this->capt ?? new \stdClass;
        $this->capt->configs = $this->capt->configs ?? [];
        $capt = $this->capt;
        app('mail.manager')->extend('smtp', function (array $config) use ($transporte, $capt) {
            $capt->configs[] = $config;

            return $transporte;
        });
    }

    /** Un servidor de correo que rechaza todo y, como hacen algunos, repite la clave en su mensaje de error. */
    protected function servidorQueFalla(?string $mensaje = null): void
    {
        $this->usarTransporte(new class($mensaje ?? '535 5.7.8 Authentication failed for password '.self::CLAVE_SMTP) implements TransportInterface {
            public function __construct(private string $mensaje) {}
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage { throw new TransportException($this->mensaje); }
            public function __toString(): string { return 'falla'; }
        });
    }

    protected function correo(int $i = 0): Email
    {
        $mensaje = $this->transporte->messages()[$i]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $mensaje);

        return $mensaje;
    }

    /** @return array<string, \Symfony\Component\Mime\Part\DataPart> adjuntos por nombre de archivo */
    protected function adjuntos(Email $correo): array
    {
        $porNombre = [];
        foreach ($correo->getAttachments() as $parte) {
            $porNombre[$parte->getFilename()] = $parte;
        }

        return $porNombre;
    }

    protected function aDirecciones(array $direcciones): array
    {
        return array_map(fn ($d) => $d->getAddress(), $direcciones);
    }

    // ------------------------------------------------------------ documentos con su comprobante, sin pasar por la emisión

    protected function clave(int $secuencial, string $codDoc = '01'): string
    {
        return '07102026'.$codDoc.'1791234567001'.'1'.'001001'.sprintf('%09d', $secuencial).'12345678'.'1'.'3';
    }

    protected function xmlDe(string $raiz, string $clave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><'.$raiz.' id="comprobante" version="1.0.0"><infoTributaria><claveAcceso>'.$clave.'</claveAcceso></infoTributaria></'.$raiz.'>';
    }

    /** El comprobante del SRI de un documento, en el estado dado. 'AUTORIZADO' trae número y fecha como los devuelve el SRI. */
    protected function comprobante(Company $empresa, \Illuminate\Database\Eloquent\Model $doc, string $tipo, string $raiz, string $estado, int $secuencial, array $extra = []): SriDocument
    {
        $clave = $this->clave($secuencial, ['factura' => '01', 'notaCredito' => '04', 'notaDebito' => '05', 'comprobanteRetencion' => '07'][$tipo]);
        $autorizado = strtoupper($estado) === 'AUTORIZADO';
        $firmado = str_replace("</$raiz>", '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></'.$raiz.'>', $this->xmlDe($raiz, $clave));

        return SriDocument::create($extra + [
            'company_id' => $empresa->id, 'documentable_type' => $doc->getMorphClass(), 'documentable_id' => $doc->getKey(),
            'tipo_comprobante' => $tipo, 'clave_acceso' => $clave, 'xml' => $this->xmlDe($raiz, $clave),
            'xml_firmado' => in_array($estado, ['generado'], true) ? null : $firmado, 'estado' => $estado, 'ambiente' => 1,
            'numero_autorizacion' => $autorizado ? $clave : null,
            'empresa_data' => ['estab' => '001', 'ptoEmi' => '001', 'secuencial' => sprintf('%09d', $secuencial)],
            'mensajes' => $autorizado ? ['RespuestaAutorizacionComprobante' => ['autorizaciones' => ['autorizacion' => [
                'estado' => 'AUTORIZADO', 'numeroAutorizacion' => $clave, 'fechaAutorizacion' => '2026-10-07T10:15:30-05:00',
                'ambiente' => 'PRUEBAS', 'comprobante' => $firmado, 'mensajes' => '',
            ]]]] : null,
            'fecha_emision' => now(),
        ]);
    }

    protected function factura(string $estado = 'AUTORIZADO', ?Company $empresa = null, ?Contact $contacto = null, array $sri = []): Invoice
    {
        $empresa ??= $this->company;
        $this->n++;
        $factura = Invoice::create([
            'company_id' => $empresa->id, 'contact_id' => ($contacto ?? $this->cliente)->id, 'numero' => sprintf('001-001-%09d', $this->n),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 2, 'precio_unitario' => 50, 'tarifa' => 15]],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'forma_pago' => 'efectivo',
            'saldo_pendiente' => 0, 'estado' => 'emitida', 'fecha_emision' => now(),
        ]);
        $this->comprobante($empresa, $factura, 'factura', 'factura', $estado, $this->n, $sri);

        return $factura->fresh();
    }

    protected function notaCredito(string $estado = 'AUTORIZADO', string $tipo = 'sri', ?string $numero = null): CreditNote
    {
        $factura = $this->factura();
        $this->n++;
        $nota = CreditNote::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => $tipo,
            'numero' => $numero ?? ($tipo === 'interna' ? 'NCI-000001' : sprintf('001-001-%09d', $this->n)), 'fecha' => now(), 'motivo' => 'Devolución parcial',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio de instalación', 'cantidad' => 1, 'precio_unitario' => 40, 'tarifa' => 15]],
            'total_sin_impuestos' => 40, 'total_impuesto' => 6, 'importe_total' => 46, 'saldo_disponible' => 46,
        ]);
        if ($tipo !== 'interna') {
            $this->comprobante($this->company, $nota, 'notaCredito', 'notaCredito', $estado, $this->n);
        }

        return $nota->fresh();
    }

    protected function notaDebito(string $estado = 'AUTORIZADO', string $tipoComprobante = 'nota_debito'): Invoice
    {
        $factura = $this->factura();
        $this->n++;
        $nd = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => sprintf('001-001-%09d', $this->n),
            'items' => [['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0]],
            'total_sin_impuestos' => 5, 'total_impuesto' => 0, 'importe_total' => 5, 'forma_pago' => '01', 'saldo_pendiente' => 5,
            'estado' => 'emitida', 'fecha_emision' => now(), 'tipo_comprobante' => $tipoComprobante,
            'numero_referencia' => $factura->numero, 'factura_referencia_id' => $factura->id,
        ]);
        if ($tipoComprobante !== 'nota_debito_interna') {
            $this->comprobante($this->company, $nd, 'notaDebito', 'notaDebito', $estado, $this->n);
        }

        return $nd->fresh();
    }

    /** Retención emitida a un proveedor con dos líneas (renta e IVA): la fila principal es la primera. */
    protected function retencion(string $estado = 'AUTORIZADO'): Withholding
    {
        $this->n++;
        $compra = Purchase::create([
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000099',
            'fecha_emision' => '2026-10-05', 'tipo_comprobante' => 'factura', 'items' => [],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'saldo_pendiente' => 115,
        ]);
        $numero = sprintf('001-001-%09d', $this->n);
        $filas = [
            ['tipo' => 'renta', 'codigo' => '303', 'porcentaje' => 10, 'base' => 100, 'valor' => 10, 'codigo_retencion' => '303'],
            ['tipo' => 'iva', 'codigo' => '725', 'porcentaje' => 100, 'base' => 15, 'valor' => 15, 'codigo_retencion' => null],
        ];
        $primera = null;
        foreach ($filas as $f) {
            $w = Withholding::create([
                'company_id' => $this->company->id, 'invoice_id' => null, 'purchase_id' => $compra->id, 'contact_id' => $this->proveedor->id,
                'tipo' => 'emitida', 'numero' => $numero, 'fecha' => '2026-10-07', 'codigo_retencion' => $f['codigo_retencion'],
                'base_imponible' => $f['base'], 'porcentaje' => $f['porcentaje'], 'total_retenido' => $f['valor'],
                'xml' => json_encode(['tipo' => $f['tipo'], 'codigo' => $f['codigo'], 'porcentaje' => $f['porcentaje'], 'base_imponible' => $f['base'], 'purchase_id' => $compra->id]),
            ]);
            $primera ??= $w;
        }
        $this->comprobante($this->company, $primera, 'comprobanteRetencion', 'comprobanteRetencion', $estado, $this->n);

        return $primera->fresh();
    }

    // ------------------------------------------------------------ la librería del SRI, falsa pero con el XML de verdad

    /**
     * Reemplaza la librería del SRI: genera el XML con la librería real, y firma, envía y autoriza sin red.
     * `autoriza` = true → el SRI autoriza todo; false → responde que sigue en proceso.
     */
    protected function sriFalso(bool $autoriza = true): object
    {
        $falso = new class extends FacturacionElectronicaLibrary {
            public bool $autoriza = true;
            /** @var array<string, string> XML firmado por clave de acceso */
            public array $firmados = [];

            public function firmarXml($tipoComprobante, $xml, $certificadoP12, $claveCertificado)
            {
                preg_match('/<claveAcceso>(.*?)<\/claveAcceso>/', $xml, $m);
                $firmado = str_replace('</'.$this->raizDe($xml).'>', '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></'.$this->raizDe($xml).'>', $xml);

                return $this->firmados[$m[1] ?? ''] = $firmado;
            }

            public function enviarSri($xmlFirmado, $ambiente)
            {
                return ['RespuestaRecepcionComprobante' => ['estado' => 'RECIBIDA', 'comprobantes' => '']];
            }

            public function autorizarSri($claveAcceso, $ambiente)
            {
                if (! $this->autoriza) {
                    return ['RespuestaAutorizacionComprobante' => ['claveAccesoConsultada' => $claveAcceso, 'numeroComprobantes' => '0', 'autorizaciones' => '']];
                }

                return ['RespuestaAutorizacionComprobante' => [
                    'claveAccesoConsultada' => $claveAcceso, 'numeroComprobantes' => '1',
                    'autorizaciones' => ['autorizacion' => [
                        'estado' => 'AUTORIZADO', 'numeroAutorizacion' => $claveAcceso, 'fechaAutorizacion' => '2026-10-07T10:15:30-05:00',
                        'ambiente' => 'PRUEBAS', 'comprobante' => $this->firmados[$claveAcceso] ?? '<comprobante/>', 'mensajes' => '',
                    ]],
                ]];
            }

            private function raizDe(string $xml): string
            {
                preg_match('/<([A-Za-z]+)[\s>]/', preg_replace('/^<\?xml[^>]*\?>/', '', $xml), $r);

                return $r[1] ?? 'comprobante';
            }
        };
        $falso->autoriza = $autoriza;
        $this->app->instance(FacturacionElectronicaLibrary::class, $falso);

        return $falso;
    }

    /** La empresa de la prueba con certificado de firma (así la emisión sí llega a firmar, enviar y autorizar). */
    protected function conCertificado(?Company $empresa = null): Company
    {
        $empresa ??= $this->company;
        $empresa->update(['certificado_p12' => 'p12-de-prueba', 'certificado_clave' => 'clave-de-prueba']);

        return $empresa->fresh();
    }
}
