<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Invoice extends Model {
    use Auditable;
    protected $fillable = ['company_id','contact_id','numero','items','total_sin_impuestos',
        'total_impuesto','importe_total','forma_pago','saldo_pendiente','estado','fecha_emision'];
    protected $casts = ['items'=>'array','fecha_emision'=>'datetime'];
    public function sriDocument() { return $this->morphOne(SriDocument::class, 'documentable'); }
    public function contact() { return $this->belongsTo(Contact::class); }
}
