<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Bank extends Model {
    protected $fillable = ['company_id','nombre','numero_cuenta','cuenta_contable'];
}
