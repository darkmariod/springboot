<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class BankMovement extends Model {
    use Auditable;
    protected $fillable = ['company_id','bank_id','fecha','tipo','monto','concepto','conciliado','documento','origen_type','origen_id'];
    protected $casts = ['conciliado'=>'boolean'];
    /** El documento que originó el movimiento (compra, factura, anticipo...); vacío en los movimientos manuales. */
    public function origen() { return $this->morphTo(); }
}
