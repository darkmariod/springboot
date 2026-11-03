<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureUserActive;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\AuditLog;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Un usuario desactivado no entra ni recibe token (T11.2). El aviso es el mismo acierte o no la contraseña:
        // así la respuesta no le confirma a nadie si la clave que probó era la buena.
        if ($user && $user->estaDesactivado()) {
            AuditLog::create([
                'user_id' => $user->id, 'accion' => 'acceso_fallido', 'modelo' => 'Sesión',
                'descripcion' => $data['email'].' (usuario desactivado)', 'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages(['email' => [EnsureUserActive::MENSAJE]]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Un intento fallido es justamente lo que interesa detectar.
            AuditLog::create([
                'user_id' => $user?->id, 'accion' => 'acceso_fallido', 'modelo' => 'Sesión',
                'descripcion' => $data['email'], 'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        AuditLog::create([
            'user_id' => $user->id, 'accion' => 'ingreso', 'modelo' => 'Sesión',
            'descripcion' => $user->email, 'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function user(Request $request)
    {
        $u = $request->user();

        // El rol viaja al frontend para que el menú muestre solo lo que le toca.
        // No es el control de acceso: cada ruta sensible lo vuelve a revisar.
        return [
            'name' => $u->name,
            'email' => $u->email,
            'rol' => $u->rol,
            'company_id' => $u->company_id,
            // El superadministrador es el operador de la plataforma: ve todas las empresas y cambia planes. El menú se adapta a esto;
            // el control real está en el servidor (middleware `tenant` y el controlador de empresas).
            'es_superadmin' => $u->esSuperadmin(),
        ];
    }

    public function logout(Request $request)
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'accion' => 'salida', 'modelo' => 'Sesión',
            'descripcion' => $request->user()->email, 'ip' => $request->ip(),
        ]);
        // Revoca en el servidor el token con el que se llamó: sin esto el token seguiría sirviendo después de «cerrar sesión».
        // Con una sesión de cookie (SPA de primera parte) no hay token que borrar.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) $token->delete();

        return response()->noContent();
    }
}
