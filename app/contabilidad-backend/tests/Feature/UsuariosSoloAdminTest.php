<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * T11.1 (D1): la gestión de usuarios es solo del administrador.
 * En la prueba final un cajero creó un usuario `admin` (201) y un contador podía hacer lo mismo eligiendo cualquier empresa.
 */
class UsuariosSoloAdminTest extends SeguridadTestCase
{
    public function test_un_cajero_no_puede_crear_usuarios_ni_darse_a_si_mismo_el_rol_admin(): void
    {
        $this->como('cajero');

        $r = $this->postJson('/api/users', $this->datosUsuario(['rol' => 'admin', 'email' => 'intruso@empresa.test']));

        $r->assertForbidden();
        $this->assertSame('Tu rol no tiene permiso para esta acción.', $r->json('message'));
        $this->assertDatabaseMissing('users', ['email' => 'intruso@empresa.test']);
    }

    public function test_un_contador_no_puede_crear_usuarios_ni_en_otra_empresa(): void
    {
        $this->como('contador');
        $otra = \App\Models\Company::create([
            'ruc' => '1790000000002', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. B', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);

        $this->postJson('/api/users', $this->datosUsuario(['rol' => 'admin', 'email' => 'otro@empresa.test']))->assertForbidden();
        $this->postJson('/api/users', $this->datosUsuario(['company_id' => $otra->id, 'email' => 'otro2@empresa.test']))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'otro@empresa.test']);
        $this->assertDatabaseMissing('users', ['email' => 'otro2@empresa.test']);
    }

    public function test_cajero_y_contador_no_pueden_listar_editar_ni_borrar_usuarios(): void
    {
        $objetivo = $this->usuario('cajero', ['name' => 'Objetivo']);

        foreach (['cajero', 'contador'] as $rol) {
            $this->como($rol);

            $this->getJson("/api/users?company_id={$this->empresa->id}")->assertForbidden();
            $this->putJson("/api/users/{$objetivo->id}", ['rol' => 'admin'])->assertForbidden();
            $this->deleteJson("/api/users/{$objetivo->id}")->assertForbidden();
        }

        $objetivo->refresh();
        $this->assertSame('cajero', $objetivo->rol);
        $this->assertSame('Objetivo', $objetivo->name);
    }

    public function test_un_cajero_no_puede_subirse_el_rol_editando_su_propio_usuario(): void
    {
        $cajero = $this->como('cajero');

        $this->putJson("/api/users/{$cajero->id}", ['rol' => 'admin'])->assertForbidden();

        $this->assertSame('cajero', $cajero->fresh()->rol);
    }

    public function test_el_administrador_crea_lista_edita_y_borra_usuarios(): void
    {
        $this->como('admin');

        $creado = $this->postJson('/api/users', $this->datosUsuario(['email' => 'nuevo@empresa.test']))->assertCreated();
        $id = $creado->json('id');
        $this->assertSame('cajero', $creado->json('rol'));

        $filas = collect($this->getJson("/api/users?company_id={$this->empresa->id}")->assertOk()->json())->pluck('email');
        $this->assertTrue($filas->contains('nuevo@empresa.test'));

        $this->putJson("/api/users/{$id}", ['rol' => 'contador'])->assertOk()->assertJsonPath('rol', 'contador');
        $this->deleteJson("/api/users/{$id}")->assertNoContent();
        $this->assertNull(User::find($id));
    }

    public function test_cualquier_usuario_sigue_pudiendo_ver_su_propio_perfil(): void
    {
        foreach (['cajero', 'contador', 'admin'] as $rol) {
            $u = $this->como($rol);
            $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $u->email)->assertJsonPath('rol', $rol);
        }
    }
}
