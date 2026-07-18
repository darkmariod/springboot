<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['company_id', 'codigo', 'nombre', 'por_defecto', 'activa'];

    protected $casts = [
        'por_defecto' => 'boolean',
        'activa' => 'boolean',
    ];

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
