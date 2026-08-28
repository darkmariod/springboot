<?php
namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Liquidación: el depósito que hace el procesador de tarjetas. */
class CardSettlement extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'fecha', 'procesador', 'lote',
        'monto_bruto', 'comision', 'monto_neto', 'estado', 'notas'];

    protected $casts = ['fecha' => 'date', 'monto_bruto' => 'decimal:2',
        'comision' => 'decimal:2', 'monto_neto' => 'decimal:2'];

    public function transactions() { return $this->hasMany(CardTransaction::class); }

    /** Diferencia entre lo asignado y lo que dice la liquidación. Cero = cuadra. */
    public function diferencia(): float
    {
        return round((float) $this->transactions()->sum('monto') - (float) $this->monto_bruto, 2);
    }
}
