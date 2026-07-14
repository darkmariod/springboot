<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchasePayment extends Model {
    protected $fillable = ['purchase_id','fecha','monto','forma_pago','bank_id','cheque_numero'];
}
