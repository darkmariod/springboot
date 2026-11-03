<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * T11.2 (D2): un usuario desactivado no inicia sesión ni sigue usando un token que ya tenía.
 * En la prueba final un usuario con `activo = false` seguía entrando y usando la API (solo `EnsureRole` miraba el estado).
 */
class UsuarioInactivoSinAccesoTest extends SeguridadTestCase
{
    private const MENSAJE = 'Tu usuario está desactivado. Habla con el administrador.';

    private function inactivo(string $clave = 'clave-segura-1'): User
    {
        return $this->usuario('cajero', ['activo' => false, 'password' => $clave, 'email' => 'inactivo@empresa.test']);
    }

    public function test_un_usuario_inactivo_no_puede_iniciar_sesion_y_no_recibe_token(): void
    {
        $u = $this->inactivo();

        $r = $this->postJson('/api/login', ['email' => 'inactivo@empresa.test', 'password' => 'clave-segura-1']);

        $r->assertStatus(422);
        $this->assertSame(self::MENSAJE, $r->json('message'));
        $this->assertSame(0, $u->tokens()->count());
        $this->assertArrayNotHasKey('token', $r->json());
    }

    public function test_la_respuesta_del_login_no_revela_si_la_contrasena_era_correcta(): void
    {
        $this->inactivo();

        $buena = $this->postJson('/api/login', ['email' => 'inactivo@empresa.test', 'password' => 'clave-segura-1']);
        $mala = $this->postJson('/api/login', ['email' => 'inactivo@empresa.test', 'password' => 'otra-clave-123']);

        $this->assertSame($buena->status(), $mala->status());
        $this->assertSame($buena->json('message'), $mala->json('message'));
        $this->assertSame(self::MENSAJE, $mala->json('message'));
    }

    public function test_un_usuario_activo_con_credenciales_correctas_si_entra(): void
    {
        $this->usuario('cajero', ['password' => 'clave-segura-1', 'email' => 'activo@empresa.test']);

        $this->postJson('/api/login', ['email' => 'activo@empresa.test', 'password' => 'clave-segura-1'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['name', 'email']]);
    }

    public function test_un_token_ya_emitido_deja_de_funcionar_en_cuanto_el_usuario_se_desactiva(): void
    {
        $u = $this->usuario('cajero');
        $token = $u->createToken('web')->plainTextToken;
        $this->conToken($token)->getJson('/api/user')->assertOk();

        // Se desactiva por fuera de la API (por ejemplo, directo en la base): el token seguía vivo
        DB::table('users')->where('id', $u->id)->update(['activo' => 0]);

        $r = $this->conToken($token)->getJson('/api/user');
        $r->assertForbidden();
        $this->assertSame(self::MENSAJE, $r->json('message'));
        $this->assertSame(0, $u->tokens()->count(), 'El token del usuario inactivo debe quedar revocado.');
        $this->conToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_el_usuario_inactivo_tampoco_pasa_por_las_rutas_sin_rol(): void
    {
        // /contacts no pide rol: antes, solo EnsureRole miraba `activo`, y esta ruta quedaba abierta
        $u = $this->usuario('cajero');
        $token = $u->createToken('web')->plainTextToken;
        DB::table('users')->where('id', $u->id)->update(['activo' => 0]);

        $this->conToken($token)->getJson("/api/contacts?company_id={$this->empresa->id}")->assertForbidden();
    }

    public function test_al_desactivar_a_un_usuario_el_admin_le_revoca_todos_sus_tokens(): void
    {
        $objetivo = $this->usuario('cajero');
        $tokenA = $objetivo->createToken('web')->plainTextToken;
        $tokenB = $objetivo->createToken('movil')->plainTextToken;
        $admin = $this->usuario('admin');
        $tokenAdmin = $admin->createToken('web')->plainTextToken;
        $this->assertSame(2, $objetivo->tokens()->count());

        $this->conToken($tokenAdmin)->putJson("/api/users/{$objetivo->id}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);

        $this->assertSame(0, $objetivo->tokens()->count());
        $this->conToken($tokenA)->getJson('/api/user')->assertUnauthorized();
        $this->conToken($tokenB)->getJson('/api/user')->assertUnauthorized();
        // El token del administrador no se toca
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_guardar_a_un_usuario_activo_sin_cambiar_el_estado_no_le_quita_la_sesion(): void
    {
        $objetivo = $this->usuario('cajero');
        $objetivo->createToken('web');
        $this->como('admin');

        $this->putJson("/api/users/{$objetivo->id}", ['name' => 'Nombre nuevo', 'activo' => true])->assertOk();

        $this->assertSame(1, $objetivo->tokens()->count());
    }

    public function test_reactivar_al_usuario_le_permite_entrar_de_nuevo(): void
    {
        $u = $this->inactivo();
        $this->postJson('/api/login', ['email' => 'inactivo@empresa.test', 'password' => 'clave-segura-1'])->assertStatus(422);

        $this->como('admin');
        $this->putJson("/api/users/{$u->id}", ['activo' => true])->assertOk()->assertJsonPath('activo', true);

        $this->postJson('/api/login', ['email' => 'inactivo@empresa.test', 'password' => 'clave-segura-1'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_el_administrador_no_puede_desactivarse_a_si_mismo_y_conserva_su_sesion(): void
    {
        $admin = $this->usuario('admin');
        $token = $admin->createToken('web')->plainTextToken;

        $this->conToken($token)->putJson("/api/users/{$admin->id}", ['activo' => false])->assertStatus(422);

        $this->assertTrue($admin->fresh()->activo);
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_un_estado_nulo_de_una_fabrica_cuenta_como_activo_solo_un_false_explicito_bloquea(): void
    {
        $u = $this->usuario('cajero');
        $u->activo = null; // en memoria, como lo dejan las fábricas de prueba
        Sanctum::actingAs($u);

        $this->getJson('/api/user')->assertOk();
    }
}
