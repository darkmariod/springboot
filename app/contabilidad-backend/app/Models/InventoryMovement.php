<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use Auditable;
    protected $fillable = ['company_id', 'product_id', 'fecha', 'tipo', 'concepto', 'cantidad',
        'costo_unitario', 'saldo_cantidad', 'saldo_costo_promedio', 'saldo_valor', 'warehouse_id', 'invoice_id', 'purchase_id'];

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function purchase()
    {
        return $this->belongsTo(\App\Models\Purchase::class);
    }
}
