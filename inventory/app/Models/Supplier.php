<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'adress'];

    public function products()
    {
        return $this->hasMany(Prodcut::class);
    }
}


