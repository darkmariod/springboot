<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CashSession extends Model {
    protected $fillable = ['company_id','cash_register_id','fecha','saldo_inicial','ingresos','egresos','saldo_final_contado','estado'];
    public function movements() { return $this->hasMany(CashMovement::class); }
}
