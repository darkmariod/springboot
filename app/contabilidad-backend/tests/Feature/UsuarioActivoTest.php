<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F9-4: la pantalla de usuarios mostraba "Usuario activo" sin marcar en usuarios guardados porque la API devolvía `activo` como
 * 1/0 (la columna no tenía conversión) y la casilla solo reconoce true/false. La API tiene que devolver un booleano de verdad,
 * y guardar un usuario sin tocar la casilla no lo desactiva.
 */
class UsuarioActivoTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Empresa SA', 'dir_matriz' => 'Av. A', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $this->admin = User::factory()->create(['rol' => 'admin', 'activo' => true, 'company_id' => $this->empresa->id]);
        Sanctum::actingAs($this->admin);
    }

    private function usuario(array $extra = []): User
    {
        return User::factory()->create($extra + ['rol' => 'cajero', 'activo' => true, 'company_id' => $this->empresa->id, 'name' => 'Ana Cajera']);
    }

    public function test_el_listado_devuelve_activo_como_booleano_no_como_1_o_0(): void
    {
        $activo = $this->usuario(['name' => 'Activa']);
        $inactivo = $this->usuario(['name' => 'Inactivo', 'activo' => false]);

        $filas = collect($this->getJson("/api/users?company_id={$this->empresa->id}")->assertOk()->json())->keyBy('id');

        $this->assertSame(true, $filas[$activo->id]['activo']);
        $this->assertSame(false, $filas[$inactivo->id]['activo']);
        $this->assertSame(true, $filas[$this->admin->id]['activo']);
    }

    public function test_crear_un_usuario_devuelve_activo_verdadero_y_queda_activo_en_la_base(): void
    {
        $r = $this->postJson('/api/users', [
            'company_id' => $this->empresa->id, 'name' => 'Nuevo', 'email' => 'nuevo@empresa.test', 'password' => 'clave-segura-1', 'rol' => 'cajero',
        ])->assertCreated();

        $this->assertSame(true, $r->json('activo'));
        $this->assertSame(1, (int) User::whereKey($r->json('id'))->value('activo'));
    }

    public function test_crear_un_usuario_inactivo_respeta_la_casilla(): void
    {
        $r = $this->postJson('/api/users', [
            'company_id' => $this->empresa->id, 'name' => 'Pausado', 'email' => 'pausado@empresa.test', 'password' => 'clave-segura-1', 'rol' => 'cajero', 'activo' => false,
        ])->assertCreated();

        $this->assertSame(false, $r->json('activo'));
        $this->assertSame(0, (int) User::whereKey($r->json('id'))->value('activo'));
    }

    public function test_guardar_el_formulario_tal_como_llego_no_desactiva_al_usuario(): void
    {
        $u = $this->usuario();
        // Lo que hace la pantalla: lee la fila del listado y la devuelve completa al guardar (con la contraseña vacía)
        $fila = collect($this->getJson("/api/users?company_id={$this->empresa->id}")->json())->firstWhere('id', $u->id);

        $r = $this->putJson("/api/users/{$u->id}", array_merge($fila, ['password' => '', 'company_id' => $this->empresa->id, 'name' => 'Ana Cajera Pérez']))->assertOk();

        $this->assertSame(true, $r->json('activo'));
        $this->assertSame('Ana Cajera Pérez', $r->json('name'));
        $this->assertSame(1, (int) User::whereKey($u->id)->value('activo'));
    }

    public function test_guardar_sin_enviar_activo_no_lo_toca(): void
    {
        $u = $this->usuario();

        $this->putJson("/api/users/{$u->id}", ['name' => 'Otro nombre'])->assertOk()->assertJsonPath('activo', true);
        $this->assertSame(1, (int) User::whereKey($u->id)->value('activo'));
    }

    public function test_desmarcar_la_casilla_desactiva_y_volver_a_marcarla_reactiva(): void
    {
        $u = $this->usuario();

        $this->putJson("/api/users/{$u->id}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertSame(0, (int) User::whereKey($u->id)->value('activo'));

        $this->putJson("/api/users/{$u->id}", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
        $this->assertSame(1, (int) User::whereKey($u->id)->value('activo'));
    }

    public function test_el_administrador_no_puede_desactivarse_a_si_mismo(): void
    {
        $this->putJson("/api/users/{$this->admin->id}", ['activo' => false])->assertStatus(422);
        $this->assertSame(1, (int) User::whereKey($this->admin->id)->value('activo'));
    }
}
