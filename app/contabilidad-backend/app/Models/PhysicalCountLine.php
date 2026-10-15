<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un producto dentro de una toma física. Sin Auditable a propósito: guardar lo contado actualiza cientos de líneas a la
 * vez y llenaría la auditoría; el cambio que importa (crear, aplicar, eliminar la toma) queda en PhysicalCount.
 */
class PhysicalCountLine extends Model
{
    protected $fillable = ['physical_count_id', 'product_id', 'stock_sistema', 'costo_promedio', 'contado',
        'stock_al_aplicar', 'diferencia', 'valor', 'inventory_movement_id'];

    public function toma()
    {
        return $this->belongsTo(PhysicalCount::class, 'physical_count_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
