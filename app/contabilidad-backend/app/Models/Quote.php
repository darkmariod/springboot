<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Quote extends Model {
    use Auditable;
    public const PENDIENTE = 'pendiente';
    public const FACTURADA = 'facturada';
    protected $fillable = ['company_id','contact_id','items','total_sin_impuestos','total_impuesto','importe_total','estado','invoice_id'];
    protected $casts = ['items'=>'array'];
    public function contact() { return $this->belongsTo(Contact::class); }
    /** La factura en que terminó la cotización (varias cotizaciones pueden terminar en la misma). */
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
