<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CreditApplication extends Model {
    protected $fillable = ['invoice_id','origen_type','origen_id','monto','fecha'];
}
