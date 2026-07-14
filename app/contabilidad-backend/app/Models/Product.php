<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Product extends Model {
    protected $fillable = ['company_id','codigo','descripcion','tipo','imagen','precio','tarifa_iva','stock','costo_promedio'];
}
