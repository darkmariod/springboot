<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PayrollLine extends Model {
    protected $fillable = ['payroll_id','employee_id','sueldo','horas_extra','comisiones',
        'aporte_personal','prestamos','anticipos','neto','aporte_patronal',
        'decimo_tercero','decimo_cuarto','fondos_reserva','vacaciones',
        'otros_ingresos','descuentos','consumos','permisos','diferido','detalle'];
    // detalle: lista de lo que entró en la línea (movimientos, consumos facturados y permisos); ver App\Support\NominaRol
    protected $casts = ['detalle'=>'array'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
