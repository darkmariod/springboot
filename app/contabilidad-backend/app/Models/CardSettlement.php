<?php
namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Liquidación: el depósito que hace el procesador de tarjetas.
 * Desde T5.3 al registrarla se asienta (Debe Bancos por el neto, Comisiones bancarias y Retenciones anticipadas /
 * Haber Tarjetas por liquidar por el bruto) y deja su movimiento en el banco. Bruto = neto + comisión + retenciones.
 */
class CardSettlement extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'fecha', 'procesador', 'lote',
        'monto_bruto', 'comision', 'monto_neto', 'estado', 'notas',
        'bank_id', 'numero', 'retencion_iva', 'retencion_renta'];

    protected $casts = ['fecha' => 'date', 'monto_bruto' => 'decimal:2',
        'comision' => 'decimal:2', 'monto_neto' => 'decimal:2',
        'retencion_iva' => 'decimal:2', 'retencion_renta' => 'decimal:2'];

    public function transactions() { return $this->hasMany(CardTransaction::class); }
    public function banco() { return $this->belongsTo(Bank::class, 'bank_id'); }

    /** Diferencia entre lo asignado y lo que dice la liquidación. Cero = cuadra. */
    public function diferencia(): float
    {
        return round((float) $this->transactions()->sum('monto') - (float) $this->monto_bruto, 2);
    }
}
