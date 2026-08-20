<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class PurchasePayment extends Model {
    use Auditable;
    protected $fillable = ['purchase_id','fecha','monto','forma_pago','bank_id','cheque_numero'];
}
