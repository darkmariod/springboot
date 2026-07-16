<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Purchase extends Model {
    use Auditable;
    protected $fillable = ['company_id','contact_id','numero','clave_acceso','fecha_emision',
        'items','total_sin_impuestos','total_impuesto','importe_total','saldo_pendiente','xml'];
    protected $casts = ['items'=>'array','fecha_emision'=>'date'];
    public function contact() { return $this->belongsTo(Contact::class); }
}
