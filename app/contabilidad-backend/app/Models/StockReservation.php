<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $fillable = [
        'company_id', 'product_id', 'contact_id', 'quantity',
        'motivo', 'expires_at', 'estado',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
