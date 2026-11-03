<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

/**
 * Corta a un usuario desactivado aunque su token siga vivo (T11.2).
 *
 * Antes solo `EnsureRole` miraba `activo`, así que un usuario con `activo = false` seguía usando todas las rutas que no piden
 * rol. Aquí se revisa en cada petición autenticada y, al detectarlo, se le revocan los tokens: no vuelve a entrar con ninguno.
 *
 * Solo un `false` explícito bloquea: las fábricas de prueba dejan `activo` en null y cuentan como activas (en la base la
 * columna nunca es nula: su valor por defecto es verdadero).
 */
class EnsureUserActive {
    public const MENSAJE = 'Tu usuario está desactivado. Habla con el administrador.';

    public function handle(Request $request, Closure $next) {
        $user = $request->user();
        if ($user && $user->estaDesactivado()) {
            $user->tokens()->delete();
            return response()->json(['message' => self::MENSAJE], 403);
        }
        return $next($request);
    }
}
