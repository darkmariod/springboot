<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Withholding extends Model {
    protected $fillable = ['company_id','invoice_id','tipo','numero','clave_acceso','fecha','total_retenido','xml'];
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
