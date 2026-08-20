<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use Auditable;
    protected $fillable = ['company_id', 'codigo', 'nombre', 'tipo', 'parent_id'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }
}
