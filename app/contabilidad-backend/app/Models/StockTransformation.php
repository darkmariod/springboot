<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fraccionamiento (una caja pasa a ser sus unidades sueltas) o conversión (un producto pasa a ser otro).
 * Conserva el valor del inventario: no necesita asiento. Los dos movimientos del kárdex la señalan como origen.
 */
class StockTransformation extends Model
{
    use Auditable;

    public const FRACCIONAMIENTO = 'fraccionamiento';
    public const CONVERSION = 'conversion';

    protected $fillable = ['company_id', 'tipo', 'numero', 'product_from_id', 'product_to_id', 'cantidad_origen',
        'cantidad_destino', 'valor_total', 'warehouse_id', 'motivo', 'user_id', 'fecha'];

    protected $casts = ['fecha' => 'date'];

    public function productoOrigen()
    {
        return $this->belongsTo(Product::class, 'product_from_id');
    }

    public function productoDestino()
    {
        return $this->belongsTo(Product::class, 'product_to_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Los movimientos del kárdex que nacieron de este documento (la salida y la entrada). */
    public function movimientos()
    {
        return $this->morphMany(InventoryMovement::class, 'origen');
    }
}
