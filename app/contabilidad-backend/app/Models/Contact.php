<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Contact extends Model {
    use Auditable;
    protected $fillable = ['company_id','es_cliente','es_proveedor','tipo_identificacion',
        'identificacion','razon_social','nombre_comercial','direccion','telefono','email','email2','parte_relacionada'];
    protected $casts = ['es_cliente'=>'boolean','es_proveedor'=>'boolean','parte_relacionada'=>'boolean'];
}
