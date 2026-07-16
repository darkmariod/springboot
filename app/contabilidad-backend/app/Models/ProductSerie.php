<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductSerie extends Model {
    protected $table = 'product_series';
    protected $fillable = ['company_id','product_id','serie','estado','purchase_id','invoice_id'];
    public function product() { return $this->belongsTo(Product::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
