<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Payroll extends Model {
    use Auditable;
    protected $fillable = ['company_id','anio','mes','total_ingresos','total_egresos',
        'total_neto','total_provisiones','estado'];
    public function lines() { return $this->hasMany(PayrollLine::class); }
}
