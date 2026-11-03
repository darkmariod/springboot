<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Carbon\Carbon;

/**
 * T11.3 (D3): cerrar sesión revoca el token en el servidor y los tokens vencen (24 h por defecto).
 * Antes `POST /api/logout` nunca se llamaba desde la pantalla y `config/sanctum.php` tenía `expiration => null`.
 */
class CerrarSesionVencimientoTest extends SeguridadTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entrar(string $rol = 'cajero'): array
    {
        $u = $this->usuario($rol, ['password' => 'clave-segura-1', 'email' => $rol.'@empresa.test']);
        $token = $this->postJson('/api/login', ['email' => $u->email, 'password' => 'clave-segura-1'])->assertOk()->json('token');

        return [$u, $token];
    }

    public function test_cerrar_sesion_revoca_el_token_en_el_servidor(): void
    {
        [$u, $token] = $this->entrar();
        $this->assertSame(1, $u->tokens()->count());
        $this->conToken($token)->getJson('/api/user')->assertOk();

        $this->conToken($token)->postJson('/api/logout')->assertNoContent();

        $this->assertSame(0, $u->tokens()->count());
        $this->conToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertTrue(AuditLog::where('user_id', $u->id)->where('accion', 'salida')->exists());
    }

    public function test_cerrar_sesion_solo_revoca_el_token_usado_no_los_de_otros_dispositivos(): void
    {
        [$u, $tokenWeb] = $this->entrar();
        $otro = $u->createToken('otro-equipo')->plainTextToken;

        $this->conToken($tokenWeb)->postJson('/api/logout')->assertNoContent();

        $this->assertSame(1, $u->tokens()->count());
        $this->conToken($otro)->getJson('/api/user')->assertOk();
    }

    public function test_cerrar_sesion_sin_token_responde_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_cerrar_sesion_con_una_sesion_de_prueba_sin_token_real_no_falla(): void
    {
        $this->como('cajero');

        $this->postJson('/api/logout')->assertNoContent();
    }

    public function test_los_tokens_vencen_a_las_24_horas_por_defecto(): void
    {
        $this->assertSame(1440, (int) config('sanctum.expiration'));
    }

    public function test_un_token_vencido_recibe_401_y_uno_vigente_funciona(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00:00'));
        [, $token] = $this->entrar();

        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00:00')->addMinutes(1439));
        $this->conToken($token)->getJson('/api/user')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00:00')->addMinutes(1441));
        $this->conToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_un_token_vencido_tampoco_deja_cerrar_sesion_ni_usar_la_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00:00'));
        [, $token] = $this->entrar();
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00'));

        $this->conToken($token)->getJson("/api/contacts?company_id={$this->empresa->id}")->assertUnauthorized();
        $this->conToken($token)->postJson('/api/logout')->assertUnauthorized();
    }
}
