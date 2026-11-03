<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Un usuario de prueba sin rol ni estado explícitos queda como lo deja la base de datos: la columna `rol` vale `admin` y
        // `activo` vale verdadero por defecto. La fábrica no los llena y el modelo en memoria se quedaba con `null`, que ahora
        // la API (con razón) no trata como administrador. Las pruebas que ejercitan un rol lo piden explícito; el resto de
        // pruebas usa «un usuario cualquiera con acceso total» y por eso necesita este valor, no un rol vacío.
        User::creating(function (User $usuario) {
            $usuario->rol ??= 'admin';
            $usuario->activo ??= true;
        });
    }
}
