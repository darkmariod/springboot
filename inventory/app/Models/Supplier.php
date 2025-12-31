<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // Agrega "use" aquí
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory; // Debes incluir el trait dentro de la clase

    protected $fillable = ["name", "email", "phone", "address"];
}
