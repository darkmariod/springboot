<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CreditNote extends Model {
    protected $fillable = ['company_id','contact_id','invoice_id','tipo','numero','fecha',
        'motivo','items','total_sin_impuestos','total_impuesto','importe_total','saldo_disponible'];
    protected $casts = ['items'=>'array','fecha'=>'date'];
    public function contact() { return $this->belongsTo(Contact::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
