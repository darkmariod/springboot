<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

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
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
