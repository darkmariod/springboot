<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStock extends Model
{
    protected $fillable = ['warehouse_id', 'product_id', 'stock'];

    protected $casts = ['stock' => 'decimal:2'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
