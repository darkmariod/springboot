<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;

class EmissionPoint extends Model
{
    use Auditable;
    protected $fillable = ['company_id', 'estab', 'punto', 'nombre', 'secuencial'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
