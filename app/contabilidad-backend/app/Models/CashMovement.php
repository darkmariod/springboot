<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class CashMovement extends Model {
    use Auditable;
    protected $fillable = ['cash_session_id','tipo','monto','concepto'];
}
