<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base de las pruebas de seguridad de la Fase 11 (usuarios, sesión, borrados y permisos de la API).
 *
 * Aquí los usuarios llevan SIEMPRE rol y estado explícitos: lo que se prueba es justamente qué puede hacer cada rol,
 * así que ninguna prueba depende de los valores por defecto.
 */
abstract class SeguridadTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Company::create([
            'ruc' => '1790000000001', 'razon_social' => 'Empresa Seguridad SA', 'dir_matriz' => 'Av. Seguridad 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
    }

    protected function usuario(string $rol, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'rol' => $rol, 'activo' => true, 'company_id' => $this->empresa->id,
            'name' => 'Usuario '.$rol, 'email' => $rol.'.'.uniqid().'@empresa.test',
        ]);
    }

    /** Entra como un usuario nuevo de ese rol (sin token real: para las pruebas de permisos). */
    protected function como(string $rol, array $extra = []): User
    {
        $usuario = $this->usuario($rol, $extra);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /**
     * Entra con un token real. Laravel guarda el usuario ya resuelto entre llamadas de una misma prueba, así que se olvida
     * antes: cada petición con este helper se autentica de verdad con el token que lleva.
     */
    protected function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token);
    }

    protected function datosUsuario(array $extra = []): array
    {
        return $extra + [
            'company_id' => $this->empresa->id, 'name' => 'Nuevo Usuario', 'email' => 'nuevo.'.uniqid().'@empresa.test',
            'password' => 'clave-segura-1', 'rol' => 'cajero',
        ];
    }
}
