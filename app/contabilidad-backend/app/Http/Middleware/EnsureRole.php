<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class EnsureRole {
    public function handle(Request $request, Closure $next, string ...$roles) {
        $user = $request->user();
        if (! $user || ! $user->activo) abort(403, 'Usuario inactivo.');
        if ($roles && ! in_array($user->rol, $roles, true)) {
            abort(403, 'Tu rol no tiene permiso para esta acción.');
        }
        return $next($request);
    }
}
