<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CashMovement extends Model {
    protected $fillable = ['cash_session_id','tipo','monto','concepto'];
}
