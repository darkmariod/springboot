<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Employee extends Model {
    use Auditable;
    protected $fillable = ['company_id','cedula','nombres','cargo','fecha_ingreso',
        'fecha_salida','sueldo','fondos_reserva','activo','contact_id'];
    protected $casts = ['fecha_ingreso'=>'date','fecha_salida'=>'date',
        'fondos_reserva'=>'boolean','activo'=>'boolean','contact_id'=>'integer'];

    /** El cliente al que se le facturan los consumos del empleado (se descuentan del rol). */
    public function contact() { return $this->belongsTo(Contact::class); }
    public function movements() { return $this->hasMany(EmployeeMovement::class); }
    public function leaves() { return $this->hasMany(EmployeeLeave::class); }
}
