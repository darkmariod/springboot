<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SriDocument extends Model {
    protected $fillable = ['company_id','documentable_type','documentable_id','tipo_comprobante',
        'clave_acceso','xml','xml_firmado','estado','numero_autorizacion','ambiente','empresa_data','mensajes','fecha_emision'];
    protected $casts = ['empresa_data'=>'array','mensajes'=>'array','fecha_emision'=>'datetime'];
    public function documentable() { return $this->morphTo(); }
}
