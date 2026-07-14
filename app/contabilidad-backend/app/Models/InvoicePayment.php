<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvoicePayment extends Model {
    protected $fillable = ['invoice_id','fecha','monto','forma_pago','bank_id','nota'];
}
