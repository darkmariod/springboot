<?php
namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Centro de costo: un área del negocio a la que se imputan ingresos y gastos. */
class CostCenter extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'codigo', 'nombre', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function invoices()  { return $this->hasMany(Invoice::class); }
    public function purchases() { return $this->hasMany(Purchase::class); }
}
