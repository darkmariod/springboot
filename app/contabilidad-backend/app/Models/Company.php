<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use Auditable;
    protected $fillable = ['logo','telefonos','agente_retencion','contribuyente_especial','sitio_web','nota_pie',
        'sri_usuario', 'sri_clave', 'sri_url_produccion', 'sri_url_pruebas', 'cert_emitido_desde', 'tipo_token', 'tiempo_generar', 'tiempo_firmar', 'tiempo_enviar', 'tiempo_autorizar', 'smtp_ssl', 'edoc_estado', 'modo_online',
        'ruc', 'razon_social', 'nombre_comercial', 'dir_matriz',
        'estab', 'pto_emi', 'secuencial', 'regimen', 'obligado_contabilidad',
        'ambiente', 'certificado_p12', 'certificado_clave', 'email_envio', 'cert_sujeto', 'cert_valido_hasta',
        'sbu', 'plan', 'plan_vence',
    ];

    protected $hidden = ['certificado_p12', 'certificado_clave'];

    /** Plan con el que nace una empresa nueva si no se elige otro. Debe existir en config/planes.php. */
    public const PLAN_POR_DEFECTO = 'completo';

    protected static function booted(): void
    {
        // La columna `plan` quedó con default 'corporativo' (migración 2026_07_28), un plan que no
        // existe en config/planes.php: la empresa nacía sin módulos y sin menú. SQLite no deja
        // cambiar ese default fácilmente, así que se corrige aquí, al crear.
        static::creating(function (Company $company) {
            if (! $company->plan || ! array_key_exists($company->plan, config('planes', []))) {
                $company->plan = self::PLAN_POR_DEFECTO;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'obligado_contabilidad' => 'boolean',
            'certificado_clave' => 'encrypted',
            'sri_clave' => 'encrypted',
            'smtp_password' => 'encrypted',
            'smtp_ssl' => 'boolean',
            'modo_online' => 'boolean',
            'plan_vence' => 'date',
            'cert_valido_hasta' => 'date',
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
