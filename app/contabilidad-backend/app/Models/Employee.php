<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Employee extends Model {
    protected $fillable = ['company_id','cedula','nombres','cargo','fecha_ingreso',
        'fecha_salida','sueldo','fondos_reserva','activo'];
    protected $casts = ['fecha_ingreso'=>'date','fecha_salida'=>'date',
        'fondos_reserva'=>'boolean','activo'=>'boolean'];
}
