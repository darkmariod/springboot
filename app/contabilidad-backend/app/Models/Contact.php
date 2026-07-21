<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Contact extends Model {
    protected $fillable = ['company_id','es_cliente','es_proveedor','tipo_identificacion',
        'identificacion','razon_social','nombre_comercial','direccion','telefono','email','email2','parte_relacionada'];
    protected $casts = ['es_cliente'=>'boolean','es_proveedor'=>'boolean','parte_relacionada'=>'boolean'];
}
