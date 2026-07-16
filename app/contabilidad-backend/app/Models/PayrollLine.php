<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PayrollLine extends Model {
    protected $fillable = ['payroll_id','employee_id','sueldo','horas_extra','comisiones',
        'aporte_personal','prestamos','anticipos','neto','aporte_patronal',
        'decimo_tercero','decimo_cuarto','fondos_reserva','vacaciones'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
