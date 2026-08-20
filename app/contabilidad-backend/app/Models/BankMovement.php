<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class BankMovement extends Model {
    use Auditable;
    protected $fillable = ['company_id','bank_id','fecha','tipo','monto','concepto','conciliado'];
    protected $casts = ['conciliado'=>'boolean'];
}
