<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Toma física de inventario de una bodega (T5.2). En borrador se captura lo contado sin tocar el kárdex; al aplicarla
 * crea un movimiento por cada diferencia y UN asiento para toda la toma, y queda inmutable. Los movimientos y el
 * asiento la señalan como su origen (origen_type / origen_id).
 */
class PhysicalCount extends Model
{
    use Auditable;

    public const BORRADOR = 'borrador';
    public const APLICADA = 'aplicada';

    protected $fillable = ['company_id', 'warehouse_id', 'numero', 'estado', 'motivo', 'fecha', 'omitidos_series',
        'valor_sobrantes', 'valor_faltantes', 'user_id', 'applied_at'];

    protected $casts = ['fecha' => 'date', 'applied_at' => 'datetime', 'omitidos_series' => 'array'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(PhysicalCountLine::class);
    }

    public function esBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    /** Los movimientos del kárdex que nacieron de esta toma. */
    public function movimientos()
    {
        return $this->morphMany(InventoryMovement::class, 'origen');
    }

    /** El asiento de la toma (uno solo), si ya se aplicó. */
    public function asiento(): ?JournalEntry
    {
        return JournalEntry::where('company_id', $this->company_id)
            ->where('origen_type', $this->getMorphClass())->where('origen_id', $this->getKey())->orderBy('id')->first();
    }
}
