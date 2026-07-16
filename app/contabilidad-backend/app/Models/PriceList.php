<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PriceList extends Model {
    protected $fillable = ['product_id','nombre','precio'];
}
