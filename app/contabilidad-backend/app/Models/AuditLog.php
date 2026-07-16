<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model {
    protected $fillable = ['company_id','user_id','accion','modelo','modelo_id',
        'descripcion','cambios','ip'];
    protected $casts = ['cambios'=>'array'];
    public function user() { return $this->belongsTo(User::class); }
}
