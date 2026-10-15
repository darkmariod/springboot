<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use Auditable;
    protected $fillable = ['company_id', 'codigo', 'nombre', 'por_defecto', 'activa', 'branch_id'];

    protected $casts = [
        'por_defecto' => 'boolean',
        'activa' => 'boolean',
    ];

    /**
     * Crea la "Bodega principal" (B01, por defecto) de una empresa recién creada para que Ajuste de inventario,
     * Fraccionamiento y Conversión tengan a dónde apuntar desde el primer día. Si la empresa ya tiene alguna
     * bodega no crea otra: devuelve la que ya usa (la marcada por defecto o, si no hay, la primera).
     * Se llama a propósito desde donde se crea la empresa; no hay un gancho global en Company::create.
     */
    public static function crearPrincipal(Company|int $company): self
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        $existente = static::where('company_id', $companyId)->orderByDesc('por_defecto')->orderBy('id')->first();
        if ($existente) {
            return $existente;
        }

        return static::create([
            'company_id' => $companyId,
            'codigo' => 'B01',
            'nombre' => 'Bodega principal',
            'por_defecto' => true,
            'activa' => true,
        ]);
    }

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
