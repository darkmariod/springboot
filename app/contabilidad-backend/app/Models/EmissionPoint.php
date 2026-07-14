<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmissionPoint extends Model
{
    protected $fillable = ['company_id', 'estab', 'punto', 'nombre', 'secuencial'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
