<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'ruc', 'razon_social', 'nombre_comercial', 'dir_matriz',
        'estab', 'pto_emi', 'secuencial', 'regimen', 'obligado_contabilidad',
        'ambiente', 'certificado_p12', 'certificado_clave',
    ];

    protected $hidden = ['certificado_p12', 'certificado_clave'];

    protected function casts(): array
    {
        return [
            'obligado_contabilidad' => 'boolean',
            'certificado_clave' => 'encrypted',
        ];
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function emissionPoints()
    {
        return $this->hasMany(EmissionPoint::class);
    }
}
