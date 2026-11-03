<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `usuario:clave`: el dueño cambia la contraseña de un usuario desde el VPS. La contraseña se teclea (sin mostrarse),
 * nunca va en un argumento ni se imprime, y al cambiarla se cierran todas las sesiones de ese usuario.
 */
class CambiarClaveUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Clave Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->usuario = User::factory()->create([
            'email' => 'dueno@prueba.test', 'rol' => 'admin', 'activo' => true, 'company_id' => $empresa->id,
            'password' => 'clave-vieja-de-prueba',
        ]);
    }

    public function test_cambia_la_clave_y_cierra_las_sesiones_abiertas_del_usuario(): void
    {
        $this->usuario->createToken('sesion-1');
        $this->usuario->createToken('sesion-2');
        $this->assertSame(2, $this->usuario->tokens()->count());

        $this->artisan('usuario:clave', ['email' => 'dueno@prueba.test'])
            ->expectsQuestion('Nueva contraseña (mínimo 10 caracteres)', 'clave-nueva-de-prueba-1')
            ->expectsQuestion('Repite la contraseña', 'clave-nueva-de-prueba-1')
            ->expectsOutputToContain('Contraseña actualizada para dueno@prueba.test')
            ->assertExitCode(0);

        $fresco = $this->usuario->fresh();
        $this->assertTrue(Hash::check('clave-nueva-de-prueba-1', $fresco->password));
        $this->assertFalse(Hash::check('clave-vieja-de-prueba', $fresco->password));
        $this->assertSame(0, $fresco->tokens()->count(), 'Cambiar la clave cierra todas sus sesiones.');
    }

    public function test_la_clave_nunca_se_imprime(): void
    {
        $this->artisan('usuario:clave', ['email' => 'dueno@prueba.test'])
            ->expectsQuestion('Nueva contraseña (mínimo 10 caracteres)', 'clave-secreta-que-no-debe-salir')
            ->expectsQuestion('Repite la contraseña', 'clave-secreta-que-no-debe-salir')
            ->doesntExpectOutputToContain('clave-secreta-que-no-debe-salir')
            ->assertExitCode(0);
    }

    public function test_rechaza_una_clave_corta_y_no_cambia_nada(): void
    {
        $antes = $this->usuario->password;

        $this->artisan('usuario:clave', ['email' => 'dueno@prueba.test'])
            ->expectsQuestion('Nueva contraseña (mínimo 10 caracteres)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->expectsOutputToContain('al menos 10 caracteres')
            ->assertExitCode(1);

        $this->assertSame($antes, $this->usuario->fresh()->password);
    }

    public function test_rechaza_si_las_dos_claves_no_coinciden(): void
    {
        $antes = $this->usuario->password;

        $this->artisan('usuario:clave', ['email' => 'dueno@prueba.test'])
            ->expectsQuestion('Nueva contraseña (mínimo 10 caracteres)', 'una-clave-larga-1')
            ->expectsQuestion('Repite la contraseña', 'una-clave-larga-2')
            ->expectsOutputToContain('no coinciden')
            ->assertExitCode(1);

        $this->assertSame($antes, $this->usuario->fresh()->password);
    }

    public function test_un_correo_que_no_existe_no_pide_ninguna_clave(): void
    {
        $this->artisan('usuario:clave', ['email' => 'nadie@prueba.test'])
            ->expectsOutputToContain('No existe un usuario con ese correo')
            ->assertExitCode(1);
    }

    public function test_la_lista_muestra_correo_rol_y_empresa_sin_ninguna_clave(): void
    {
        $this->artisan('usuario:clave', ['--lista' => true])
            ->expectsOutputToContain('dueno@prueba.test')
            ->doesntExpectOutputToContain('clave-vieja-de-prueba')
            ->assertExitCode(0);
    }
}
