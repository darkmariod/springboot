<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cambia la contraseña de un usuario desde la consola (por ejemplo, desde el VPS) sin que la clave pase por el chat,
 * por un argumento ni por el historial del terminal: se teclea, no se muestra y este comando jamás la imprime.
 *
 *   php artisan usuario:clave --lista                      ← ver correo, rol y empresa de cada usuario (sin claves)
 *   php artisan usuario:clave correo@del.usuario           ← pide la nueva clave dos veces
 *
 * Al cambiarla se cierran todas las sesiones abiertas de ese usuario.
 */
class CambiarClaveUsuario extends Command
{
    protected $signature = 'usuario:clave
        {email? : Correo del usuario}
        {--lista : Muestra los usuarios (correo, rol, empresa), sin ninguna clave}';

    protected $description = 'Cambia la contraseña de un usuario pidiéndola por teclado (sin mostrarla) y cierra sus sesiones';

    private const MINIMO = 10;

    public function handle(): int
    {
        if ($this->option('lista')) {
            return $this->lista();
        }

        $email = trim((string) $this->argument('email'));
        if ($email === '') {
            $this->error('Indica el correo del usuario: php artisan usuario:clave correo@del.usuario  (o usa --lista para verlos).');

            return self::FAILURE;
        }

        $usuario = User::where('email', $email)->first();
        if (! $usuario) {
            $this->error('No existe un usuario con ese correo. Usa --lista para ver los que hay.');

            return self::FAILURE;
        }

        $clave = (string) $this->secret('Nueva contraseña (mínimo '.self::MINIMO.' caracteres)');
        $repetida = (string) $this->secret('Repite la contraseña');

        if (mb_strlen($clave) < self::MINIMO) {
            $this->error('La contraseña debe tener al menos '.self::MINIMO.' caracteres. No se cambió nada.');

            return self::FAILURE;
        }
        if (! hash_equals($clave, $repetida)) {
            $this->error('Las dos contraseñas no coinciden. No se cambió nada.');

            return self::FAILURE;
        }

        // El modelo cifra la contraseña (cast `hashed`); se usa Hash::make aquí por claridad y por si el cast cambia.
        $usuario->forceFill(['password' => Hash::make($clave)])->save();
        $cerradas = $usuario->tokens()->count();
        $usuario->tokens()->delete();

        $this->info('Contraseña actualizada para '.$usuario->email.'.'.($cerradas > 0 ? " Se cerraron {$cerradas} sesión(es) abierta(s)." : ''));

        return self::SUCCESS;
    }

    private function lista(): int
    {
        $filas = User::orderBy('id')->get(['id', 'email', 'rol', 'company_id', 'es_superadmin', 'activo'])
            ->map(fn (User $u) => [
                $u->id, $u->email, $u->rol, $u->company_id ?? '—',
                $u->es_superadmin ? 'sí' : 'no', $u->activo === false ? 'no' : 'sí',
            ])->all();

        $this->table(['ID', 'Correo', 'Rol', 'Empresa', 'Superadmin', 'Activo'], $filas);

        return self::SUCCESS;
    }
}
