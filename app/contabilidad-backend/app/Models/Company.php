<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'ruc', 'razon_social', 'nombre_comercial', 'dir_matriz',
        'estab', 'pto_emi', 'secuencial', 'regimen', 'obligado_contabilidad',
        'ambiente', 'certificado_p12', 'certificado_clave', 'email_envio', 'cert_sujeto', 'cert_valido_hasta',
        'sbu', 'plan', 'plan_vence',
    ];

    protected $hidden = ['certificado_p12', 'certificado_clave'];

    protected function casts(): array
    {
        return [
            'obligado_contabilidad' => 'boolean',
            'certificado_clave' => 'encrypted',
            'plan_vence' => 'date',
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

    public function features(): array {
        return config('planes.'.$this->plan.'.features', []);
    }
    public function tieneFeature(string $feature): bool {
        if ($this->planVencido()) return false;
        return in_array($feature, $this->features(), true);
    }
    public function planVencido(): bool {
        return $this->plan_vence !== null && $this->plan_vence->isPast();
    }
}
