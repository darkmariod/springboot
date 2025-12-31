<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    // Se corrigió 'proctected' por 'protected'
    protected $fillable = [
        'sku',
        'name',
        'description',
        'price',
        'cost',
        'stock',
        'min_stock',
        'category_id',
        'supplier_id'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
