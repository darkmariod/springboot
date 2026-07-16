<?php
namespace App\Models\Concerns;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable {
    public static function bootAuditable(): void {
        static::created(fn($m) => $m->registrarAuditoria('creo'));
        static::updated(fn($m) => $m->registrarAuditoria('actualizo', $m->getChanges()));
        static::deleted(fn($m) => $m->registrarAuditoria('elimino'));
    }
    public function registrarAuditoria(string $accion, array $cambios = []): void {
        if (! Auth::check()) return;
        unset($cambios['updated_at']);
        AuditLog::create([
            'company_id'  => $this->company_id ?? null,
            'user_id'     => Auth::id(),
            'accion'      => $accion,
            'modelo'      => class_basename($this),
            'modelo_id'   => $this->getKey(),
            'descripcion' => $this->numero ?? $this->razon_social ?? $this->descripcion ?? null,
            'cambios'     => $cambios ?: null,
            'ip'          => Request::ip(),
        ]);
    }
}
