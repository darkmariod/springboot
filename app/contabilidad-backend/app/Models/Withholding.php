<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Withholding extends Model {
    use Auditable;
    protected $fillable = ['company_id','invoice_id','tipo','numero','clave_acceso','fecha','total_retenido','xml'];
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
