<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BankMovement extends Model {
    protected $fillable = ['company_id','bank_id','fecha','tipo','monto','concepto','conciliado'];
    protected $casts = ['conciliado'=>'boolean'];
}
