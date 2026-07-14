<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Quote extends Model {
    protected $fillable = ['company_id','contact_id','items','total_sin_impuestos','total_impuesto','importe_total','estado'];
    protected $casts = ['items'=>'array'];
    public function contact() { return $this->belongsTo(Contact::class); }
}
