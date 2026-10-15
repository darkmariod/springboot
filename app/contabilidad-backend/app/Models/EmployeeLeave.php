<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Permiso, vacaciones, enfermedad u otra ausencia de un empleado (T7.1). Los días y el descuento los calcula App\Support\VacacionesEmpleado. */
class EmployeeLeave extends Model {
    use Auditable;

    public const TIPOS = ['permiso', 'vacaciones', 'enfermedad', 'otro'];
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado', 'cancelado'];

    protected $fillable = ['company_id','employee_id','tipo','fecha_desde','fecha_hasta','dias','remunerado','observacion','estado'];
    protected $casts = ['fecha_desde'=>'date:Y-m-d','fecha_hasta'=>'date:Y-m-d','dias'=>'float','remunerado'=>'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
