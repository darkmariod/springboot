<?php
namespace App\Models;
use App\Services\EnviarComprobanteCorreo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class SriDocument extends Model {
    /**
     * Estados con que el SRI deja un comprobante rechazado. Es definitivo para esa clave de acceso: no vuelve a la cola de
     * "Autorizar todo" y esa factura no cuenta como venta. 'generado', 'firmado', 'enviado' y 'AUTORIZADO' no están aquí.
     */
    public const ESTADOS_RECHAZADO = ['NO AUTORIZADO', 'NO AUTORIZADA', 'RECHAZADO', 'RECHAZADA', 'DEVUELTA', 'DEVUELTO'];

    protected $fillable = ['company_id','documentable_type','documentable_id','tipo_comprobante',
        'clave_acceso','xml','xml_firmado','estado','numero_autorizacion','ambiente','empresa_data','mensajes','fecha_emision'];
    protected $casts = ['empresa_data'=>'array','mensajes'=>'array','fecha_emision'=>'datetime'];
    public function documentable() { return $this->morphTo(); }

    /**
     * Envío automático por correo (T10.2). Es EL punto central: cualquier camino que deje el comprobante AUTORIZADO
     * (la emisión normal, "Autorizar todo", reanudar…) pasa por aquí al guardar el cambio de estado.
     *  - Solo cuenta el CAMBIO a autorizado al actualizar: un comprobante que nace autorizado (los XML que se importan)
     *    no dispara nada, ni uno que ya lo estaba.
     *  - Corre al terminar la transacción que lo guardó (si la hay) y se descarta si esa transacción se deshace.
     *  - El servicio atrapa cualquier falla: el correo nunca puede romper ni deshacer una autorización.
     */
    protected static function booted(): void
    {
        static::updated(function (SriDocument $doc) {
            if (! $doc->wasChanged('estado') || ! $doc->autorizado() || self::esAutorizado($doc->getOriginal('estado'))) {
                return;
            }
            DB::afterCommit(function () use ($doc) {
                try {
                    app(EnviarComprobanteCorreo::class)->alAutorizarse($doc);
                } catch (\Throwable $e) {
                    // El servicio ya registra sus propias fallas; esto cubre que ni siquiera se haya podido construir
                    report($e);
                }
            });
        });
    }

    /** El SRI escribe "AUTORIZADO" en mayúsculas; el código de siempre también guardó "autorizado". */
    public static function esAutorizado(?string $estado): bool {
        return strtoupper(trim((string) $estado)) === 'AUTORIZADO';
    }
    public static function esRechazado(?string $estado): bool {
        return in_array(strtoupper(trim((string) $estado)), self::ESTADOS_RECHAZADO, true);
    }
    public function autorizado(): bool { return self::esAutorizado($this->estado); }

    /** El nodo "autorizacion" de la respuesta guardada del SRI (estado, número, fecha, comprobante…), o [] si no hay. */
    public function nodoAutorizacion(): array {
        $m = $this->mensajes;
        if (! is_array($m)) return [];
        $nodo = $m['RespuestaAutorizacionComprobante']['autorizaciones']['autorizacion'] ?? null;
        if (is_array($nodo) && ! isset($nodo['estado']) && isset($nodo[0])) $nodo = $nodo[0];   // varias autorizaciones: la primera
        return is_array($nodo) ? $nodo : [];
    }

    /** Fecha y hora de autorización tal como la devolvió el SRI, o null si la respuesta guardada no la trae. */
    public function fechaAutorizacion(): ?Carbon {
        $v = $this->nodoAutorizacion()['fechaAutorizacion'] ?? null;
        if (! is_string($v) || trim($v) === '') return null;
        try { return Carbon::parse($v); } catch (\Throwable) { return null; }
    }

    /**
     * El XML autorizado, en el formato con que el SRI entrega un comprobante autorizado: el nodo <autorizacion> con el estado,
     * el número y la fecha de autorización, el ambiente y el comprobante firmado dentro de un CDATA. Solo existe para un
     * comprobante AUTORIZADO; si ya viene como <autorizacion> (los XML importados) se entrega tal cual.
     */
    public function xmlAutorizado(): ?string {
        if (! $this->autorizado()) return null;
        $nodo = $this->nodoAutorizacion();
        $comprobante = '';
        foreach ([$nodo['comprobante'] ?? null, $this->xml_firmado, $this->xml] as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') { $comprobante = trim($candidato); break; }
        }
        if ($comprobante === '') return null;
        if (preg_match('/^(<\?xml[^>]*\?>\s*)?<autorizacion[\s>]/i', $comprobante)) return $comprobante;

        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $numero = trim((string) ($nodo['numeroAutorizacion'] ?? '')) ?: trim((string) $this->numero_autorizacion);
        $fecha = trim((string) ($nodo['fechaAutorizacion'] ?? ''));
        $ambiente = (int) ($this->ambiente ?: 0) === 2 ? 'PRODUCCIÓN' : 'PRUEBAS';
        // Un CDATA no puede contener "]]>": se parte en dos para que el comprobante salga idéntico
        $cdata = '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $comprobante).']]>';

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<autorizacion>'
            .'<estado>AUTORIZADO</estado>'
            .'<numeroAutorizacion>'.$esc($numero).'</numeroAutorizacion>'
            .($fecha !== '' ? '<fechaAutorizacion>'.$esc($fecha).'</fechaAutorizacion>' : '')
            .'<ambiente>'.$esc($ambiente).'</ambiente>'
            .'<comprobante>'.$cdata.'</comprobante>'
            .'<mensajes/>'
            .'</autorizacion>';
    }

    /**
     * El número del comprobante (001-001-000000123) según lo que se emitió: establecimiento, punto de emisión y secuencial de su
     * infoTributaria o, si no están, los de su clave de acceso. Una nota de crédito guarda su número DESPUÉS de emitirse, así
     * que al autorizarse todavía no lo tiene en su propia fila.
     */
    public function numeroComprobante(): ?string {
        $t = is_array($this->empresa_data) ? $this->empresa_data : [];
        $estab = preg_replace('/\D/', '', (string) ($t['estab'] ?? ''));
        $pto = preg_replace('/\D/', '', (string) ($t['ptoEmi'] ?? ''));
        $sec = preg_replace('/\D/', '', (string) ($t['secuencial'] ?? ''));
        if ($estab !== '' && $pto !== '' && $sec !== '') {
            return sprintf('%03d-%03d-%09d', (int) $estab, (int) $pto, (int) $sec);
        }
        $clave = trim((string) $this->clave_acceso);
        if (strlen($clave) === 49 && ctype_digit($clave)) {
            return substr($clave, 24, 3).'-'.substr($clave, 27, 3).'-'.substr($clave, 30, 9);
        }
        return null;
    }

    /**
     * El documento al que pertenece ya se anuló en el sistema (facturas y notas de débito: estado; notas de crédito: tipo).
     * Su comprobante no debe salir al SRI: allá quedaría válido estando anulado aquí.
     */
    public function documentoAnulado(): bool {
        $doc = $this->documentable;
        return $doc && ($doc->getAttribute('estado') === 'anulado' || $doc->getAttribute('tipo') === 'anulado');
    }
    public function rechazado(): bool { return self::esRechazado($this->estado); }
}
