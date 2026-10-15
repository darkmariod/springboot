<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ficha de un activo fijo (T7.2). El costo, el valor residual y la vida útil fijan la cuota mensual en línea recta; la depreciación ya
 * contabilizada vive en `fixed_asset_depreciations` (una fila por mes). La lógica contable está en App\Support\ActivosFijos y
 * App\Support\DepreciacionActivos.
 */
class FixedAsset extends Model {
    use Auditable;

    public const ACTIVO = 'activo';
    public const DADO_DE_BAJA = 'dado_de_baja';

    /** Grupos del activo: clave => nombre. Las vidas útiles sugeridas viven solo en la pantalla; el servidor no impone mínimos legales. */
    public const GRUPOS = [
        'muebles_enseres' => 'Muebles y enseres',
        'equipos_computacion' => 'Equipos de computación',
        'maquinaria_equipo' => 'Maquinaria y equipo',
        'vehiculos' => 'Vehículos',
        'edificios' => 'Edificios',
        'otros' => 'Otros',
    ];

    public const METODOS = ['linea_recta'];

    protected $fillable = ['company_id','codigo','nombre','descripcion','grupo','departamento','employee_id','fecha_adquisicion','costo',
        'valor_residual','vida_util_meses','metodo','fecha_inicio_depreciacion','estado','fecha_baja','motivo_baja','valor_venta',
        'purchase_id','serie','observacion','journal_entry_alta_id','journal_entry_baja_id'];
    protected $casts = [
        'fecha_adquisicion'=>'date:Y-m-d','fecha_inicio_depreciacion'=>'date:Y-m-d','fecha_baja'=>'date:Y-m-d',
        'costo'=>'float','valor_residual'=>'float','valor_venta'=>'float','vida_util_meses'=>'integer',
        'employee_id'=>'integer','purchase_id'=>'integer',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function depreciaciones() { return $this->hasMany(FixedAssetDepreciation::class); }
}
