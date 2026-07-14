<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InventoryMovement extends Model {
    protected $fillable = ['company_id','product_id','fecha','tipo','concepto','cantidad',
        'costo_unitario','saldo_cantidad','saldo_costo_promedio','saldo_valor'];
}
