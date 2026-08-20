<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class InvoicePayment extends Model {
    use Auditable;
    protected $fillable = ['invoice_id','fecha','monto','forma_pago','bank_id','nota'];
}
