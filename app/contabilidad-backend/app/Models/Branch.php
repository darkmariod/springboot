<?php
namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Sucursal = establecimiento ante el SRI. */
class Branch extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'estab', 'nombre', 'direccion', 'telefono', 'es_matriz', 'activa'];

    protected $casts = ['es_matriz' => 'boolean', 'activa' => 'boolean'];

    public function company()        { return $this->belongsTo(Company::class); }
    public function warehouses()     { return $this->hasMany(Warehouse::class); }
    public function emissionPoints() { return $this->hasMany(EmissionPoint::class); }
    public function invoices()       { return $this->hasMany(Invoice::class); }
}
