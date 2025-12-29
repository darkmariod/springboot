<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'adress'];

    public functio()
    {
        return $this->hasMany(Prodcut::class);
    }
}


