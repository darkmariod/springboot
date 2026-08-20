<?php
namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    /** Nunca se guardan en el log: son secretos o ruido. */
    private static array $camposOcultos = [
        'password', 'remember_token', 'certificado_p12', 'certificado_clave',
        'sri_clave', 'smtp_password', 'logo', 'xml', 'xml_firmado',
        'updated_at', 'created_at',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->registrarAuditoria('creo'));
        static::updated(fn ($m) => $m->registrarAuditoria('actualizo', $m->getChanges()));
        static::deleted(fn ($m) => $m->registrarAuditoria('elimino'));
    }

    public function registrarAuditoria(string $accion, array $cambios = []): void
    {
        foreach (self::$camposOcultos as $c) {
            // Se deja constancia de que cambió, pero sin exponer el valor.
            if (array_key_exists($c, $cambios)) {
                $cambios[$c] = in_array($c, ['updated_at', 'created_at'], true) ? null : '«dato protegido»';
                if ($cambios[$c] === null) unset($cambios[$c]);
            }
        }

        AuditLog::create([
            'company_id'  => $this->company_id ?? ($this->getTable() === 'companies' ? $this->getKey() : null),
            'user_id'     => Auth::id(),                      // null = acción del sistema
            'accion'      => $accion,
            'modelo'      => class_basename($this),
            'modelo_id'   => $this->getKey(),
            'descripcion' => $this->numero ?? $this->razon_social ?? $this->descripcion
                             ?? $this->name ?? $this->codigo ?? null,
            'cambios'     => $cambios ?: null,
            'ip'          => Request::ip(),
        ]);
    }

    /** Para dejar constancia de acciones que no son cambios de tabla. */
    public static function auditarAccion(string $accion, ?string $descripcion = null, array $datos = [], $companyId = null): void
    {
        AuditLog::create([
            'company_id' => $companyId,
            'user_id'    => Auth::id(),
            'accion'     => $accion,
            'modelo'     => class_basename(static::class),
            'modelo_id'  => null,
            'descripcion'=> $descripcion,
            'cambios'    => $datos ?: null,
            'ip'         => Request::ip(),
        ]);
    }
}
