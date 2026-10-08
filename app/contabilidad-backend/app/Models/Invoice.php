<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Invoice extends Model {
    use Auditable;
    /** Las notas de débito viven en esta misma tabla: 'nota_debito' va al SRI, 'nota_debito_interna' solo regula saldos. */
    public const TIPOS_NOTA_DEBITO = ['nota_debito', 'nota_debito_interna'];
    protected $fillable = ['cost_center_id','branch_id','company_id','contact_id','numero','items','total_sin_impuestos',
        'total_impuesto','importe_total','forma_pago','saldo_pendiente','estado','fecha_emision',
        'tipo_comprobante','numero_referencia','factura_referencia_id'];
    protected $casts = ['items'=>'array','fecha_emision'=>'datetime'];
    public function sriDocument() { return $this->morphOne(SriDocument::class, 'documentable'); }
    public function contact() { return $this->belongsTo(Contact::class); }
    public function journalEntries() { return $this->morphMany(JournalEntry::class, 'origen'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    /** Notas de crédito emitidas contra esta factura (incluye las anuladas: filtra por tipo). */
    public function notasCredito() { return $this->hasMany(CreditNote::class); }
    /** Notas de débito (SRI o internas) que le cargan valor a esta factura. */
    public function notasDebito() { return $this->hasMany(Invoice::class, 'factura_referencia_id'); }
    /** Una factura de venta normal: ni nota de débito ni otro comprobante que comparta la tabla. */
    public function esFactura(): bool { return in_array($this->tipo_comprobante, [null, 'factura'], true); }
    /**
     * Solo facturas de venta (mismo criterio que esFactura). Los reportes de ventas (ATS, 104, panel, centros de costo)
     * lo usan para no contar como venta una nota de débito, del SRI o interna, que comparte esta tabla.
     */
    public function scopeSoloFacturas($query) {
        return $query->where(fn ($q) => $q->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', 'factura'));
    }
    /**
     * Facturas que cuentan como VENTA en los reportes y el panel: facturas no anuladas cuyo comprobante del SRI sigue vigente
     * (generado, firmado, enviado, autorizado… o sin comprobante, como las ventas internas) y que el SRI no rechazó.
     * Antes solo contaban las AUTORIZADAS: una empresa sin certificado (todo "generado") veía ceros.
     * Con $soloAutorizadas = true vuelve al criterio estricto: solo las que el SRI autorizó.
     */
    public function scopeVentasVigentes($query, bool $soloAutorizadas = false) {
        $query->soloFacturas()->where('estado', '!=', 'anulado');
        if ($soloAutorizadas) {
            return $query->whereHas('sriDocument', fn ($sd) => $sd->whereRaw('UPPER(estado) = ?', ['AUTORIZADO']));
        }
        $rechazados = SriDocument::ESTADOS_RECHAZADO;
        return $query->whereDoesntHave('sriDocument', fn ($sd) => $sd->whereRaw(
            'UPPER(estado) in ('.implode(',', array_fill(0, count($rechazados), '?')).')', $rechazados));
    }
    /** Estado del comprobante del SRI en minúsculas ('generado', 'autorizado'…), o 'sin sri' si la factura no tiene comprobante. */
    public function estadoSri(): string {
        $sri = $this->sriDocument;
        return $sri && trim((string) $sri->estado) !== '' ? strtolower(trim((string) $sri->estado)) : 'sin sri';
    }
    /** ¿Tiene notas de crédito o de débito vigentes (no anuladas)? Mientras las tenga, no se anula. */
    public function tieneNotasVigentes(): bool {
        return $this->notasCredito()->where('tipo', '!=', 'anulado')->exists()
            || $this->notasDebito()->where('estado', '!=', 'anulado')->exists()
            || CreditApplication::where('invoice_id', $this->id)->where('origen_type', (new CreditNote)->getMorphClass())
                ->whereIn('origen_id', CreditNote::where('tipo', '!=', 'anulado')->select('id'))->exists();
    }
}
