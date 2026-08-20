<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\AuditLog;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

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
        return ['name' => $request->user()->name, 'email' => $request->user()->email];
    }

    public function logout(Request $request)
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'accion' => 'salida', 'modelo' => 'Sesión',
            'descripcion' => $request->user()->email, 'ip' => $request->ip(),
        ]);
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
