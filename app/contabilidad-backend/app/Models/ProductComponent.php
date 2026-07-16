<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductComponent extends Model {
    protected $fillable = ['product_id','component_id','cantidad'];
    public function component() { return $this->belongsTo(Product::class, 'component_id'); }
}
