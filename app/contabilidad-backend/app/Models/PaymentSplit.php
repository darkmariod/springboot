<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSplit extends Model
{
    protected $fillable = [
        'company_id', 'pagable_type', 'pagable_id', 'tipo', 'fecha', 'valor',
        'bank_id', 'cash_register_id', 'documento', 'detalle',
    ];

    protected $casts = ['fecha' => 'date'];

    public function pagable()
    {
        return $this->morphTo();
    }
}
