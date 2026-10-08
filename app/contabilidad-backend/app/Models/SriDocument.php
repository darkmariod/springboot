<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
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

    /** El SRI escribe "AUTORIZADO" en mayúsculas; el código de siempre también guardó "autorizado". */
    public static function esAutorizado(?string $estado): bool {
        return strtoupper(trim((string) $estado)) === 'AUTORIZADO';
    }
    public static function esRechazado(?string $estado): bool {
        return in_array(strtoupper(trim((string) $estado)), self::ESTADOS_RECHAZADO, true);
    }
    public function autorizado(): bool { return self::esAutorizado($this->estado); }
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
