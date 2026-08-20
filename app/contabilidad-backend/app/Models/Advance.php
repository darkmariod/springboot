<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Advance extends Model {
    use Auditable;
    protected $fillable = ['company_id','contact_id','fecha','monto','saldo','forma_pago','bank_id','nota'];
    protected $casts = ['fecha'=>'date'];
    public function contact() { return $this->belongsTo(Contact::class); }
}
