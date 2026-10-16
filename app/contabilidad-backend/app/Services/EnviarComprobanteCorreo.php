<?php

namespace App\Services;

use App\Mail\ComprobanteMail;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\SriDocument;
use App\Models\Withholding;
use App\Support\ResultadoCorreo;
use App\Support\RidePdf;
use App\Support\TextoSeguro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Envía un comprobante electrónico (factura, nota de crédito, nota de débito o retención emitida) al correo del cliente o proveedor,
 * con el RIDE en PDF y el XML adjuntos, por el servidor de correo de LA EMPRESA emisora.
 *
 * Reglas:
 *  - El envío es síncrono y con tiempo de espera corto: en producción no hay un worker de colas.
 *  - NUNCA lanza. Una falla del correo se registra como `error` y se devuelve en el resultado; no rompe ni deshace nada.
 *  - Cada intento deja una fila en `email_logs` (enviado, error u omitido). El motivo se guarda recortado y sin secretos.
 *  - Se usa un mailer propio de la empresa, armado al enviar con su host, puerto, usuario y clave: el mailer global no se toca.
 *  - El envío automático (al autorizarse el comprobante) solo ocurre si la empresa lo activó y jamás se repite para el mismo documento.
 */
class EnviarComprobanteCorreo
{
    /** Segundos de espera para conectar y para cada respuesta del servidor de correo. */
    public const TIEMPO_ESPERA = 8;

    public const ETIQUETAS = [
        'factura' => 'Factura', 'nota_credito' => 'Nota de crédito', 'nota_debito' => 'Nota de débito', 'retencion' => 'Comprobante de retención',
    ];

    /**
     * Empresas cuyo servidor de correo ya falló en esta misma operación (un proceso = una petición). Evita que "Autorizar todo"
     * espere el tiempo de espera completo una vez por cada comprobante cuando el servidor no responde.
     *
     * @var array<int, true>
     */
    private static array $servidorCaido = [];

    public function __construct(private RideBuilder $ride) {}

    /** Olvida las fallas recordadas del proceso (para las pruebas). */
    public static function reiniciar(): void
    {
        self::$servidorCaido = [];
    }

    // ------------------------------------------------------------------------------------------ qué se puede enviar

    /** factura | nota_credito | nota_debito | retencion, o null si ese documento no se envía por correo. */
    public static function tipoDe(Model $documento): ?string
    {
        if ($documento instanceof Invoice) {
            if ($documento->esFactura()) {
                return 'factura';
            }

            return in_array($documento->tipo_comprobante, Invoice::TIPOS_NOTA_DEBITO, true) ? 'nota_debito' : null;
        }
        if ($documento instanceof CreditNote) {
            return 'nota_credito';
        }
        if ($documento instanceof Withholding) {
            return $documento->tipo === 'emitida' ? 'retencion' : null;
        }

        return null;
    }

    /** Las notas internas solo regulan saldos: no son comprobantes del SRI y no se envían. */
    public static function esInterna(Model $documento): bool
    {
        if ($documento instanceof CreditNote) {
            return $documento->tipo === 'interna' || str_starts_with((string) $documento->numero, 'NCI-');
        }

        return $documento instanceof Invoice && $documento->tipo_comprobante === 'nota_debito_interna';
    }

    /** Los correos del cliente (o del proveedor, en una retención), sin repetir y solo los válidos. */
    public function destinatariosDe(Model $documento): array
    {
        $contacto = $this->contactoDe($this->principal($documento));

        return $this->limpiarDestinatarios([$contacto?->email, $contacto?->email2]);
    }

    /** @return string[] */
    public function limpiarDestinatarios(array $correos): array
    {
        $vistos = [];
        foreach ($correos as $correo) {
            $correo = trim((string) $correo);
            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) && ! isset($vistos[mb_strtolower($correo)])) {
                $vistos[mb_strtolower($correo)] = $correo;
            }
        }

        return array_values($vistos);
    }

    // ------------------------------------------------------------------------------------------ puntos de entrada

    /**
     * Punto de entrada del envío automático: lo llama SriDocument cuando el comprobante pasa a AUTORIZADO.
     * Atrapa todo; no devuelve nada si el documento no corresponde (guía, liquidación, nota interna, anulado).
     */
    public function alAutorizarse(SriDocument $sri): ?ResultadoCorreo
    {
        try {
            $documento = $sri->documentable;
            if (! $documento || self::tipoDe($documento) === null || self::esInterna($documento) || $sri->documentoAnulado()) {
                return null;
            }

            return $this->enviar($documento, $this->destinatariosDe($documento), true);
        } catch (Throwable $e) {
            Log::warning('Envío automático de comprobante: '.TextoSeguro::limpiar($e->getMessage()));

            return null;
        }
    }

    /**
     * Envía el comprobante. `$destinatarios` son los correos a los que va; los inválidos se descartan.
     * Nunca lanza: devuelve el resultado (enviado | error | omitido) y deja la fila de la bitácora.
     */
    public function enviar(Model $documento, array $destinatarios, bool $automatico = false, ?int $userId = null): ResultadoCorreo
    {
        $tipo = self::tipoDe($documento);
        if ($tipo === null) {
            return new ResultadoCorreo(EmailLog::OMITIDO, 'Este documento no se envía por correo.');
        }

        $company = null;
        $destinatarios = $this->limpiarDestinatarios($destinatarios);
        try {
            $documento = $this->principal($documento);
            $company = Company::findOrFail($documento->company_id);

            return $this->intentar($documento, $tipo, $company, $destinatarios, $automatico, $userId);
        } catch (Throwable $e) {
            // Último recurso: algo no previsto. Se deja constancia y se sigue, sin propagar nada
            $motivo = 'No se pudo preparar el correo: '.TextoSeguro::limpiar($e->getMessage(), [$company?->smtp_password], 250);
            Log::warning('Correo de comprobante: '.$motivo);
            try {
                $log = $this->registrar($documento, $tipo, $company?->id ?? (int) $documento->company_id, $destinatarios, null, EmailLog::ERROR, $motivo, $automatico, [], $userId);
            } catch (Throwable) {
                $log = null;
            }

            return new ResultadoCorreo(EmailLog::ERROR, $motivo, $destinatarios, $log);
        }
    }

    /** Correo de prueba con la configuración de la empresa (por el mismo camino que los comprobantes). Lanza RuntimeException legible. */
    public function enviarPrueba(Company $company, string $destinatario): void
    {
        $cfg = $this->configuracionSmtp($company);
        if (! $cfg) {
            throw new RuntimeException('Configura el servidor de correo primero (servidor, usuario y clave) y guarda antes de probar.');
        }
        $remitente = $this->remitente($company);
        if (! $remitente) {
            throw new RuntimeException('Falta el correo de envío de la empresa: escríbelo en "Correo de envío" y guarda antes de probar.');
        }

        $nombre = $this->abrirMailer($company, $cfg);
        try {
            Mail::mailer($nombre)->raw(
                "Este es un correo de prueba del sistema de facturación.\n\n"
                ."Si lo recibiste, la configuración de correo de {$company->razon_social} funciona "
                .'y los comprobantes electrónicos van a llegar al correo del cliente.',
                function ($mensaje) use ($remitente, $destinatario, $company) {
                    $mensaje->from($remitente->address, $remitente->name)->to($destinatario)->subject('Prueba de correo — '.$company->razon_social);
                },
            );
        } catch (Throwable $e) {
            throw new RuntimeException($this->explicar($e, $cfg), 0, $e);
        } finally {
            $this->cerrarMailer($nombre);
        }
    }

    // ------------------------------------------------------------------------------------------ el envío

    private function intentar(Model $documento, string $tipo, Company $company, array $destinatarios, bool $automatico, ?int $userId): ResultadoCorreo
    {
        $sri = $documento->sriDocument()->first();
        $contacto = $this->contactoDe($documento);

        $omitir = fn (string $motivo, ?string $asunto = null) => $this->omitido($documento, $tipo, $company, $destinatarios, $asunto, $motivo, $automatico, $userId);

        if ($automatico) {
            if (! $company->enviar_comprobantes_automaticamente) {
                return $omitir('El envío automático de comprobantes está desactivado para esta empresa.');
            }
            if (EmailLog::yaSeEnvioAutomaticamente($documento)) {
                // Ya salió: no se repite ni se ensucia la bitácora
                return new ResultadoCorreo(EmailLog::OMITIDO, 'Este comprobante ya se envió automáticamente.', $destinatarios);
            }
        }
        if (! $sri) {
            return $omitir('Este documento no tiene comprobante electrónico del SRI.');
        }
        if (! $destinatarios) {
            return $omitir($tipo === 'retencion'
                ? 'El proveedor no tiene un correo electrónico registrado: escribe uno para enviar la retención.'
                : 'El cliente no tiene un correo electrónico registrado: escribe uno para enviar el comprobante.');
        }
        $cfg = $this->configuracionSmtp($company);
        if (! $cfg) {
            return $omitir('La empresa no tiene configurado el servidor de correo (servidor, usuario y clave). Se configura en e-documents.');
        }
        $remitente = $this->remitente($company);
        if (! $remitente) {
            return $omitir('Falta el correo de envío de la empresa (campo "Correo de envío" en e-documents).');
        }

        $datos = $this->describir($documento, $tipo, $company, $sri, $contacto);
        $asunto = $datos['etiqueta'].' '.$datos['numero'].' de '.$company->razon_social;

        // 1) Los adjuntos: si algo falla aquí el correo no sale, pero el comprobante queda intacto
        try {
            [$archivos, $adjuntosLog, $textoAdjuntos, $sinXml] = $this->adjuntos($documento, $tipo, $sri, $datos);
        } catch (Throwable $e) {
            $motivo = 'No se pudo generar el RIDE en PDF: '.TextoSeguro::limpiar($e->getMessage(), [], 250);
            Log::warning('Correo de comprobante '.$datos['numero'].': '.$motivo);

            return $this->fallado($documento, $tipo, $company, $destinatarios, $asunto, $motivo, $automatico, [], $userId);
        }

        // 2) Si el servidor de correo ya falló en esta operación, no se vuelve a esperar su tiempo de espera
        if ($automatico && isset(self::$servidorCaido[$company->id])) {
            return $this->fallado($documento, $tipo, $company, $destinatarios, $asunto,
                'No se intentó: el servidor de correo no respondió en un envío anterior de esta misma operación. Reenvíalo desde el comprobante.',
                $automatico, $adjuntosLog, $userId);
        }

        $info = $datos['info'] + ['asunto' => $asunto, 'adjuntos' => $textoAdjuntos];
        $correo = new ComprobanteMail($asunto, $remitente, $this->responderA($company), $info, $archivos);
        $correo->to($destinatarios);

        $nombre = $this->abrirMailer($company, $cfg);
        try {
            Mail::mailer($nombre)->send($correo);
        } catch (Throwable $e) {
            self::$servidorCaido[$company->id] = true;
            $motivo = $this->explicar($e, $cfg);
            Log::warning('Correo de comprobante '.$datos['numero'].' (empresa '.$company->id.'): '.$motivo);

            return $this->fallado($documento, $tipo, $company, $destinatarios, $asunto, $motivo, $automatico, $adjuntosLog, $userId);
        } finally {
            $this->cerrarMailer($nombre);
        }

        $log = $this->registrar($documento, $tipo, $company->id, $destinatarios, $asunto, EmailLog::ENVIADO,
            $sinXml ? 'Se envió sin XML: el comprobante todavía no tiene un XML generado.' : null, $automatico, $adjuntosLog, $userId, now());

        return new ResultadoCorreo(EmailLog::ENVIADO, 'Correo enviado a '.implode(', ', $destinatarios).'.', $destinatarios, $log);
    }

    /**
     * Los adjuntos: el RIDE en PDF y el XML. Con el comprobante AUTORIZADO el XML es el autorizado por el SRI; si no, el firmado
     * (o el generado) y su nombre lo dice. Sin ningún XML se envía solo el PDF y se anota en la bitácora.
     *
     * @return array{0: array<int, array{nombre:string,mime:string,datos:string}>, 1: array<int, array<string, mixed>>, 2: string[], 3: bool}
     */
    private function adjuntos(Model $documento, string $tipo, SriDocument $sri, array $datos): array
    {
        $ride = match ($tipo) {
            'factura' => $this->ride->paraFactura($documento),
            'nota_credito' => $this->ride->paraNotaCredito($documento),
            'nota_debito' => $this->ride->paraNotaDebito($documento),
            'retencion' => $this->ride->paraRetencion($documento),
        };
        $pdf = RidePdf::bytes($ride);
        $nombrePdf = RidePdf::nombreArchivo($ride);
        // dompdf deja mucha memoria ocupada: se libera antes de seguir con el siguiente comprobante
        gc_collect_cycles();

        $archivos = [['nombre' => $nombrePdf, 'mime' => 'application/pdf', 'datos' => $pdf]];
        $texto = ['Representación impresa del comprobante (RIDE) en PDF: '.$nombrePdf];
        $base = trim((string) $sri->clave_acceso) !== '' ? trim((string) $sri->clave_acceso) : preg_replace('/[^A-Za-z0-9._-]+/', '-', $datos['etiqueta'].'-'.$datos['numero']);

        $xml = null;
        $nombreXml = null;
        if ($sri->autorizado() && ($autorizado = $sri->xmlAutorizado())) {
            $xml = $autorizado;
            $nombreXml = $base.'.xml';
            $texto[] = 'Comprobante electrónico autorizado por el SRI, en XML: '.$nombreXml;
        } elseif (($crudo = $sri->xml_firmado ?: $sri->xml) && trim((string) $crudo) !== '') {
            $xml = (string) $crudo;
            $nombreXml = $base.'-sin-autorizacion.xml';
            $texto[] = 'Comprobante electrónico en XML, todavía sin autorización del SRI: '.$nombreXml;
        }
        if ($xml !== null) {
            $archivos[] = ['nombre' => $nombreXml, 'mime' => 'application/xml', 'datos' => $xml];
        }

        $log = array_map(fn (array $a) => ['nombre' => $a['nombre'], 'tipo' => $a['mime'], 'bytes' => strlen($a['datos'])], $archivos);
        if ($xml === null) {
            $log[] = ['nombre' => null, 'tipo' => 'application/xml', 'bytes' => 0, 'omitido' => 'El comprobante no tiene XML generado.'];
        }

        return [$archivos, $log, $texto, $xml === null];
    }

    /** Lo que el correo dice del comprobante. */
    private function describir(Model $documento, string $tipo, Company $company, SriDocument $sri, ?Contact $contacto): array
    {
        [$numero, $fecha, $total] = match ($tipo) {
            'factura', 'nota_debito' => [(string) $documento->numero, $documento->fecha_emision, (float) $documento->importe_total],
            'nota_credito' => [(string) ($documento->numero ?: $sri->numeroComprobante()), $documento->fecha, (float) $documento->importe_total],
            'retencion' => [(string) $documento->numero, $documento->fecha,
                (float) Withholding::where('company_id', $documento->company_id)->where('tipo', 'emitida')->where('numero', $documento->numero)
                    ->when($documento->purchase_id, fn ($q, $id) => $q->where('purchase_id', $id))->sum('total_retenido')],
        };
        $fechaEmision = $fecha ? \Carbon\Carbon::parse($fecha)->format('d/m/Y') : '—';
        $etiqueta = self::ETIQUETAS[$tipo];
        $autorizado = $sri->autorizado();
        $contactoEmisor = trim((string) $company->email_envio);
        if (trim((string) $company->telefonos) !== '') {
            $contactoEmisor .= ($contactoEmisor !== '' ? ' o al ' : '').trim((string) $company->telefonos);
        }

        return [
            'etiqueta' => $etiqueta,
            'numero' => $numero,
            'info' => [
                'emisor' => (string) $company->razon_social,
                'emisor_ruc' => (string) $company->ruc,
                'cliente' => trim((string) $contacto?->razon_social) !== '' ? trim((string) $contacto->razon_social) : ($tipo === 'retencion' ? 'proveedor' : 'cliente'),
                'tipo' => $etiqueta,
                'numero' => $numero,
                'fecha' => $fechaEmision,
                'total' => number_format($total, 2, '.', ''),
                'etiqueta_total' => $tipo === 'retencion' ? 'Valor retenido' : 'Valor total',
                'autorizado' => $autorizado,
                'numero_autorizacion' => $autorizado ? trim((string) $sri->numero_autorizacion) : null,
                'fecha_autorizacion' => $autorizado ? $sri->fechaAutorizacion()?->format('d/m/Y H:i:s') : null,
                'contacto' => $contactoEmisor,
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------ el servidor de correo

    /**
     * Configuración SMTP de la empresa en el formato de un mailer de Laravel, o null si le falta servidor, usuario o clave.
     * El puerto 465 usa SSL desde el inicio; en cualquier otro, con "Usar SSL" marcado, se exige STARTTLS (nunca se manda la clave
     * sin cifrar). El tiempo de espera es corto: no hay worker de colas y el usuario está esperando.
     */
    public function configuracionSmtp(Company $company): ?array
    {
        $host = trim((string) $company->smtp_host);
        $usuario = trim((string) $company->smtp_user);
        $clave = (string) $company->smtp_password;
        if ($host === '' || $usuario === '' || $clave === '') {
            return null;
        }

        $puerto = (int) ($company->smtp_port ?: 587);
        $implicito = $puerto === 465;
        $cfg = [
            'transport' => 'smtp', 'scheme' => $implicito ? 'smtps' : 'smtp', 'host' => $host, 'port' => $puerto,
            'username' => $usuario, 'password' => $clave, 'timeout' => self::TIEMPO_ESPERA,
        ];
        if (! $implicito && $company->smtp_ssl) {
            $cfg['require_tls'] = true;
        }
        $dominio = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($dominio && ! in_array($dominio, ['localhost', '127.0.0.1'], true)) {
            $cfg['local_domain'] = $dominio;
        }

        return $cfg;
    }

    /** Remitente: el correo de envío de la empresa (o, si no hay, el usuario SMTP cuando es un correo) con su razón social. */
    private function remitente(Company $company): ?Address
    {
        foreach ([$company->email_envio, $company->smtp_user] as $correo) {
            $correo = trim((string) $correo);
            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return new Address($correo, (string) $company->razon_social);
            }
        }

        return null;
    }

    private function responderA(Company $company): ?Address
    {
        $correo = trim((string) $company->email_envio);

        return $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) ? new Address($correo, (string) $company->razon_social) : null;
    }

    /** Registra el mailer propio de la empresa (con otro nombre: el mailer global no se toca) y devuelve su nombre. */
    private function abrirMailer(Company $company, array $cfg): string
    {
        $nombre = 'correo_empresa_'.$company->id;
        config(['mail.mailers.'.$nombre => $cfg]);
        Mail::purge($nombre);

        return $nombre;
    }

    private function cerrarMailer(string $nombre): void
    {
        Mail::purge($nombre);
        $mailers = (array) config('mail.mailers');
        unset($mailers[$nombre]);
        config(['mail.mailers' => $mailers]);
    }

    /** El motivo de una falla del servidor de correo, en español y sin la clave. */
    private function explicar(Throwable $e, array $cfg): string
    {
        $crudo = $e->getMessage();
        $detalle = TextoSeguro::limpiar($crudo, [$cfg['password'] ?? null], 160);
        $servidor = ($cfg['host'] ?? '?').':'.($cfg['port'] ?? '?');

        $explicacion = match (true) {
            (bool) preg_match('/\b535\b|authenticat|credentials|username and password|auth/i', $crudo) => 'El servidor de correo rechazó el usuario o la clave. Con Gmail usa una contraseña de aplicación, no la clave normal de la cuenta.',
            (bool) preg_match('/starttls/i', $crudo) => 'El servidor de correo ('.$servidor.') no ofrece conexión segura (STARTTLS) en ese puerto. Prueba con el puerto 465 o revisa la opción "Usar SSL".',
            (bool) preg_match('/could not be established|timed out|timeout|refused|unreachable|resolve|getaddrinfo|name or service/i', $crudo) => 'No se pudo conectar con el servidor de correo ('.$servidor.'). Revisa el servidor, el puerto y la conexión a internet.',
            default => 'El servidor de correo no aceptó el mensaje.',
        };

        return TextoSeguro::limpiar($explicacion.' (Detalle: '.$detalle.')', [$cfg['password'] ?? null], 450);
    }

    // ------------------------------------------------------------------------------------------ la bitácora

    private function omitido(Model $documento, string $tipo, Company $company, array $destinatarios, ?string $asunto, string $motivo, bool $automatico, ?int $userId): ResultadoCorreo
    {
        $log = $this->registrar($documento, $tipo, $company->id, $destinatarios, $asunto, EmailLog::OMITIDO, $motivo, $automatico, [], $userId);

        return new ResultadoCorreo(EmailLog::OMITIDO, $motivo, $destinatarios, $log);
    }

    private function fallado(Model $documento, string $tipo, Company $company, array $destinatarios, ?string $asunto, string $motivo, bool $automatico, array $adjuntos, ?int $userId): ResultadoCorreo
    {
        $log = $this->registrar($documento, $tipo, $company->id, $destinatarios, $asunto, EmailLog::ERROR, $motivo, $automatico, $adjuntos, $userId);

        return new ResultadoCorreo(EmailLog::ERROR, 'No se pudo enviar el correo. '.$motivo, $destinatarios, $log);
    }

    private function registrar(Model $documento, string $tipo, int $companyId, array $destinatarios, ?string $asunto, string $estado, ?string $motivo, bool $automatico, array $adjuntos, ?int $userId, $enviadoEn = null): EmailLog
    {
        return EmailLog::create([
            'company_id' => $companyId,
            'documentable_type' => $documento->getMorphClass(),
            'documentable_id' => $documento->getKey(),
            'tipo_comprobante' => $tipo,
            'destinatarios' => $destinatarios,
            'asunto' => $asunto !== null ? mb_substr($asunto, 0, 255) : null,
            'estado' => $estado,
            'motivo' => $motivo !== null ? TextoSeguro::limpiar($motivo, [], 480) : null,
            'automatico' => $automatico,
            'adjuntos' => $adjuntos ?: null,
            'user_id' => $userId,
            'sent_at' => $enviadoEn,
        ]);
    }

    // ------------------------------------------------------------------------------------------ auxiliares

    /** De las filas de una retención (una por línea) la principal: la primera, la que lleva el comprobante del SRI. */
    public function principal(Model $documento): Model
    {
        if ($documento instanceof Withholding) {
            return Withholding::where('company_id', $documento->company_id)->where('tipo', 'emitida')->where('numero', $documento->numero)
                ->when($documento->purchase_id, fn ($q, $id) => $q->where('purchase_id', $id))->orderBy('id')->first() ?? $documento;
        }

        return $documento;
    }

    private function contactoDe(Model $documento): ?Contact
    {
        if ($documento instanceof Withholding) {
            return $documento->contact_id ? Contact::find($documento->contact_id) : null;
        }
        if (! $documento instanceof Invoice && ! $documento instanceof CreditNote) {
            throw new InvalidArgumentException('Documento sin contacto.');
        }

        return $documento->contact()->first();
    }
}
