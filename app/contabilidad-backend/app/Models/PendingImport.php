<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PendingImport extends Model {
    protected $fillable = ['company_id','clave_acceso','ruc_emisor','razon_social',
        'fecha','estado','error'];
}
