<?php

namespace Tests\Feature\Reuniones;

use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\SeguridadTestCase;

/**
 * Seguridad de acceso para vender HasReset a varios clientes (30 de octubre de 2026): el usuario desactivado no entra
 * y cerrar sesión revoca el token en el servidor. Usa `SeguridadTestCase` (una empresa y usuarios con rol explícito).
 */
#[Group('reuniones')]
class PlataformaAccesoTest extends SeguridadTestCase
{
    private const DESACTIVADO = 'Tu usuario está desactivado. Habla con el administrador.';

    /**
     * Pedido por: PLAT
     * Pedido: «Si desactivo a un usuario, no debe poder entrar, y si ya estaba conectado, que lo saque.»
     * Se prueba: el usuario desactivado no inicia sesión (422 sin token); al desactivar a uno conectado el administrador le revoca los tokens; reactivado, entra otra vez.
     */
    #[Group('reuniones')]
    #[Group('R27')]
    public function test_R27_un_usuario_desactivado_no_entra_y_si_estaba_conectado_pierde_la_sesion(): void
    {
        // Arrange: una cajera conectada con un token real y el administrador con el suyo
        $cajera = $this->usuario('cajero', ['password' => 'clave-segura-1', 'email' => 'cajera@empresa.test']);
        $tokenCajera = $this->postJson('/api/login', ['email' => 'cajera@empresa.test', 'password' => 'clave-segura-1'])->assertOk()->json('token');
        $admin = $this->usuario('admin');
        $tokenAdmin = $admin->createToken('web')->plainTextToken;
        $this->conToken($tokenCajera)->getJson('/api/user')->assertOk();

        // Act 1: el administrador la desactiva
        $this->conToken($tokenAdmin)->putJson("/api/users/{$cajera->id}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);

        // Assert 1: su token ya no sirve y no puede volver a entrar
        $this->assertSame(0, $cajera->tokens()->count());
        $this->conToken($tokenCajera)->getJson('/api/user')->assertUnauthorized();
        $r = $this->postJson('/api/login', ['email' => 'cajera@empresa.test', 'password' => 'clave-segura-1'])->assertStatus(422);
        $this->assertSame(self::DESACTIVADO, $r->json('message'));
        $this->assertArrayNotHasKey('token', $r->json());
        $this->assertSame(0, $cajera->tokens()->count());
        // Assert 1b: y la respuesta no revela si la clave era correcta
        $mala = $this->postJson('/api/login', ['email' => 'cajera@empresa.test', 'password' => 'otra-clave-123']);
        $this->assertSame($r->status(), $mala->status());
        $this->assertSame($r->json('message'), $mala->json('message'));

        // Act 2 + Assert 2: reactivada, entra de nuevo
        $this->conToken($tokenAdmin)->putJson("/api/users/{$cajera->id}", ['activo' => true])->assertOk();
        $this->postJson('/api/login', ['email' => 'cajera@empresa.test', 'password' => 'clave-segura-1'])->assertOk()->assertJsonStructure(['token']);
    }

    /**
     * Pedido por: PLAT
     * Pedido: «Cuando alguien cierra sesión, su acceso tiene que quedar cancelado en el servidor, no solo borrarse de la pantalla.»
     * Se prueba: al cerrar sesión el token se revoca (el siguiente uso da 401) y queda en la auditoría; el token de otro equipo del mismo usuario sigue vivo.
     */
    #[Group('reuniones')]
    #[Group('R27')]
    public function test_R27_al_cerrar_sesion_el_token_se_revoca_en_el_servidor(): void
    {
        // Arrange: el contador con dos sesiones, en la web y en otro equipo
        $contador = $this->usuario('contador', ['password' => 'clave-segura-1', 'email' => 'contador@empresa.test']);
        $web = $this->postJson('/api/login', ['email' => 'contador@empresa.test', 'password' => 'clave-segura-1'])->assertOk()->json('token');
        $otro = $contador->createToken('otro-equipo')->plainTextToken;
        $this->assertSame(2, $contador->tokens()->count());
        $this->conToken($web)->getJson('/api/user')->assertOk();

        // Act: cierra sesión en la web
        $this->conToken($web)->postJson('/api/logout')->assertNoContent();

        // Assert: ese token murió en el servidor, el otro equipo sigue y queda en la auditoría
        $this->assertSame(1, $contador->tokens()->count());
        $this->conToken($web)->getJson('/api/user')->assertUnauthorized();
        $this->conToken($otro)->getJson('/api/user')->assertOk();
        $this->assertTrue(AuditLog::where('user_id', $contador->id)->where('accion', 'salida')->exists());
        // Assert: sin token, cerrar sesión da 401
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
