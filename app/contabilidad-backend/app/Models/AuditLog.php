<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría: SOLO SE ESCRIBE, nunca se edita ni se borra.
 * Si se pudiera modificar, no serviría como evidencia ante una revisión.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'accion', 'modelo', 'modelo_id',
        'descripcion', 'cambios', 'ip',
    ];

    protected $casts = ['cambios' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException(
            'El registro de auditoría no se puede modificar.'));
        static::deleting(fn () => throw new \RuntimeException(
            'El registro de auditoría no se puede eliminar.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
