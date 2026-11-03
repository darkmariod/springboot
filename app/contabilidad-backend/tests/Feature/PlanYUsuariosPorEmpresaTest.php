<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * T11.6b: el plan y su vencimiento son del operador de la plataforma (superadministrador); la lista de empresas, los usuarios y
 * las cuentas respetan el aislamiento. Sin esto, el administrador de un cliente podría "mejorarse" el plan por la API.
 */
class PlanYUsuariosPorEmpresaTest extends AislamientoTestCase
{
    // ------------------------------------------------------------------ plan y vencimiento

    public function test_el_administrador_de_un_cliente_no_se_mejora_el_plan_y_nada_cambia(): void
    {
        $this->a->update(['plan' => 'basico', 'plan_vence' => '2027-01-31']);
        $this->confinado();

        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo', 'plan_vence' => '2030-12-31'])->assertForbidden();

        $this->assertSame('basico', $this->a->fresh()->plan);
        $this->assertSame('2027-01-31', $this->a->fresh()->plan_vence->toDateString());
    }

    public function test_el_administrador_de_un_cliente_tampoco_cambia_el_plan_por_la_edicion_de_la_empresa(): void
    {
        $this->a->update(['plan' => 'basico', 'plan_vence' => '2027-01-31']);
        $this->confinado();

        $this->putJson("/api/companies/{$this->a->id}", ['plan' => 'completo'])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->putJson("/api/companies/{$this->a->id}", ['plan_vence' => '2035-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_vence');
        $this->putJson("/api/companies/{$this->a->id}", ['plan_vence' => null])
            ->assertStatus(422)->assertJsonValidationErrors('plan_vence');
        // Mezclar un cambio permitido con uno prohibido: no se guarda NADA
        $this->putJson("/api/companies/{$this->a->id}", ['razon_social' => 'Nombre nuevo', 'plan' => 'negocio'])->assertStatus(422);

        $fresca = $this->a->fresh();
        $this->assertSame('basico', $fresca->plan);
        $this->assertSame('2027-01-31', $fresca->plan_vence->toDateString());
        $this->assertSame('Empresa A SA', $fresca->razon_social);
    }

    public function test_el_formulario_de_la_empresa_puede_reenviar_el_plan_tal_como_esta(): void
    {
        $this->a->update(['plan' => 'basico', 'plan_vence' => '2027-01-31']);
        $this->confinado();

        // Un formulario que reenvía todo el registro (plan y vencimiento sin cambios) sigue funcionando
        $this->putJson("/api/companies/{$this->a->id}", [
            'razon_social' => 'Empresa A Renombrada', 'plan' => 'basico', 'plan_vence' => '2027-01-31T00:00:00.000000Z',
        ])->assertOk()->assertJsonPath('company.razon_social', 'Empresa A Renombrada');

        $this->assertSame('basico', $this->a->fresh()->plan);
        $this->assertSame('Empresa A Renombrada', $this->a->fresh()->razon_social);
    }

    public function test_el_administrador_de_un_cliente_si_edita_los_datos_de_su_empresa(): void
    {
        $this->confinado();

        $this->putJson("/api/companies/{$this->a->id}", ['razon_social' => 'Mi Empresa SA', 'telefonos' => '022222222', 'dir_matriz' => 'Av. Nueva 99'])
            ->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $this->a->id, 'razon_social' => 'Mi Empresa SA', 'telefonos' => '022222222']);
        $this->assertDatabaseHas('companies', ['id' => $this->b->id, 'razon_social' => 'Empresa B SA']);
    }

    public function test_el_administrador_de_un_cliente_no_usa_el_ruc_de_otra_empresa(): void
    {
        $this->confinado();

        $this->putJson("/api/companies/{$this->a->id}", ['ruc' => $this->b->ruc])->assertStatus(422)->assertJsonValidationErrors('ruc');
    }

    public function test_el_contador_y_el_cajero_no_cambian_nada_de_la_empresa(): void
    {
        foreach (['contador', 'cajero'] as $rol) {
            $this->confinado($rol);
            $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo'])->assertForbidden();
            $this->putJson("/api/companies/{$this->a->id}", ['razon_social' => 'X'])->assertForbidden();
        }
        $this->assertSame('Empresa A SA', $this->a->fresh()->razon_social);
    }

    public function test_el_usuario_sin_empresa_que_no_es_superadmin_tampoco_cambia_el_plan(): void
    {
        $this->sinEmpresa();

        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'basico'])->assertForbidden();
        $this->putJson("/api/companies/{$this->a->id}", ['plan' => 'basico'])->assertStatus(422);
        $this->assertSame('completo', $this->a->fresh()->plan);
    }

    public function test_el_superadmin_cambia_el_plan_y_el_vencimiento_de_cualquier_empresa(): void
    {
        $this->superadmin();

        $this->postJson("/api/companies/{$this->b->id}/plan", ['plan' => 'negocio', 'plan_vence' => '2028-06-30'])
            ->assertOk()->assertJsonPath('plan', 'negocio')->assertJsonPath('vence', '2028-06-30');
        $this->assertSame('negocio', $this->b->fresh()->plan);

        $this->putJson("/api/companies/{$this->b->id}", ['plan' => 'basico', 'plan_vence' => '2029-01-01'])->assertOk();
        $this->assertSame('basico', $this->b->fresh()->plan);
        $this->assertSame('2029-01-01', $this->b->fresh()->plan_vence->toDateString());

        // Y un plan que no existe se rechaza
        $this->postJson("/api/companies/{$this->b->id}/plan", ['plan' => 'corporativo'])->assertStatus(422);
        $this->putJson("/api/companies/{$this->b->id}", ['plan' => 'corporativo'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ lista de empresas

    public function test_la_lista_de_empresas_de_un_cliente_trae_solo_la_suya(): void
    {
        $this->confinado();

        $r = $this->getJson('/api/companies')->assertOk();
        $this->assertCount(1, $r->json());
        $this->assertSame($this->a->id, $r->json('0.id'));
        $this->assertStringNotContainsString('Empresa B SA', $r->getContent());
    }

    public function test_la_lista_de_empresas_del_cajero_y_del_contador_tambien_es_solo_la_suya(): void
    {
        foreach (['cajero', 'contador'] as $rol) {
            $this->confinado($rol, $this->b);
            $this->assertSame([$this->b->id], array_column($this->getJson('/api/companies')->assertOk()->json(), 'id'));
        }
    }

    public function test_el_superadmin_y_el_usuario_sin_empresa_ven_todas_las_empresas(): void
    {
        $this->superadmin();
        $this->assertCount(2, $this->getJson('/api/companies')->assertOk()->json());

        $this->sinEmpresa();
        $this->assertCount(2, $this->getJson('/api/companies')->assertOk()->json());
    }

    public function test_el_perfil_dice_si_es_superadmin(): void
    {
        $this->superadmin();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('es_superadmin', true)->assertJsonPath('rol', 'admin');

        $this->confinado();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('es_superadmin', false)->assertJsonPath('company_id', $this->a->id);
    }

    // ------------------------------------------------------------------ usuarios

    public function test_el_administrador_de_un_cliente_lista_solo_los_usuarios_de_su_empresa_sin_el_superadmin(): void
    {
        $this->confinado();
        $this->superadmin(); // vive en la empresa A, pero no es del cliente
        $this->confinado();

        $nombres = array_column($this->getJson('/api/users')->assertOk()->json(), 'name');

        $this->assertContains('Usuario MARCA-A', $nombres);
        $this->assertNotContains('Usuario MARCA-B', $nombres);
        $this->assertNotContains('Operador', $nombres);
    }

    public function test_pedir_los_usuarios_de_otra_empresa_da_403(): void
    {
        $this->confinado();

        $this->getJson("/api/users?company_id={$this->b->id}")->assertForbidden();
    }

    public function test_el_superadmin_lista_los_usuarios_de_la_empresa_que_elige(): void
    {
        $this->superadmin();

        $nombres = array_column($this->getJson("/api/users?company_id={$this->b->id}")->assertOk()->json(), 'name');

        $this->assertContains('Usuario MARCA-B', $nombres);
        $this->assertNotContains('Usuario MARCA-A', $nombres);
    }

    public function test_el_administrador_de_un_cliente_crea_usuarios_solo_en_su_empresa(): void
    {
        $this->confinado();

        // Sin company_id: se crea en la suya
        $r = $this->postJson('/api/users', ['name' => 'Nuevo A', 'email' => 'nuevo.a@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'cajero'])
            ->assertCreated();
        $this->assertSame($this->a->id, $r->json('company_id'));
        $this->assertDatabaseHas('users', ['email' => 'nuevo.a@cliente.test', 'company_id' => $this->a->id, 'es_superadmin' => false]);

        // Con el company_id de otra empresa: 403 y no se crea
        $this->postJson('/api/users', ['company_id' => $this->b->id, 'name' => 'Intruso', 'email' => 'intruso@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'admin'])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'intruso@cliente.test']);
    }

    public function test_nadie_crea_un_superadmin_por_la_api_ni_marca_a_nadie_como_tal(): void
    {
        $this->confinado();
        $this->postJson('/api/users', ['name' => 'Quiero ser jefe', 'email' => 'jefe@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'admin', 'es_superadmin' => true])
            ->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'jefe@cliente.test', 'es_superadmin' => false]);

        $this->putJson('/api/users/'.$this->ids['a']['usuario'], ['es_superadmin' => true])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->ids['a']['usuario'], 'es_superadmin' => false]);

        // Tampoco el superadmin: otro superadmin se crea por consola
        $this->superadmin();
        $this->postJson('/api/users', ['company_id' => $this->b->id, 'name' => 'Otro jefe', 'email' => 'otro.jefe@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'admin', 'es_superadmin' => true])
            ->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'otro.jefe@cliente.test', 'es_superadmin' => false]);
        $this->putJson('/api/users/'.$this->ids['b']['usuario'], ['es_superadmin' => true])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->ids['b']['usuario'], 'es_superadmin' => false]);
    }

    public function test_el_superadmin_debe_decir_en_que_empresa_crea_el_usuario(): void
    {
        $this->superadmin();

        $this->postJson('/api/users', ['name' => 'Sin empresa', 'email' => 'sin.empresa@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'cajero'])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->assertDatabaseMissing('users', ['email' => 'sin.empresa@cliente.test']);

        $this->postJson('/api/users', ['company_id' => $this->b->id, 'name' => 'En B', 'email' => 'en.b@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'cajero'])
            ->assertCreated()->assertJsonPath('company_id', $this->b->id);
    }

    public function test_el_punto_de_emision_de_un_usuario_tiene_que_ser_de_su_empresa(): void
    {
        $this->superadmin();

        // Un punto de la empresa A para un usuario de B: lo rechaza la validación, no solo el aislamiento
        $this->postJson('/api/users', ['company_id' => $this->b->id, 'name' => 'En B', 'email' => 'en.b@cliente.test', 'password' => 'clave-segura-1', 'rol' => 'cajero', 'emission_point_id' => $this->ids['a']['punto']])
            ->assertStatus(422)->assertJsonValidationErrors('emission_point_id');
        $this->putJson('/api/users/'.$this->ids['b']['usuario'], ['emission_point_id' => $this->ids['a']['punto']])
            ->assertStatus(422)->assertJsonValidationErrors('emission_point_id');
        $this->putJson('/api/users/'.$this->ids['b']['usuario'], ['emission_point_id' => $this->ids['b']['punto']])->assertOk();
    }

    public function test_cambiar_o_borrar_usuarios_solo_dentro_de_la_empresa(): void
    {
        $this->confinado();

        $this->putJson('/api/users/'.$this->ids['b']['usuario'], ['name' => 'Cambiado'])->assertForbidden();
        $this->deleteJson('/api/users/'.$this->ids['b']['usuario'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->ids['b']['usuario'], 'name' => 'Usuario MARCA-B']);

        $this->putJson('/api/users/'.$this->ids['a']['usuario'], ['name' => 'Cambiado'])->assertOk();
        $this->deleteJson('/api/users/'.$this->ids['a']['usuario'])->assertNoContent();
    }

    public function test_el_company_id_de_un_usuario_nunca_cambia_por_la_api(): void
    {
        $this->confinado();
        // El mismo company_id de la propia empresa pasa el aislamiento, pero la edición no mueve al usuario
        $this->putJson('/api/users/'.$this->ids['a']['usuario'], ['company_id' => $this->a->id, 'name' => 'Sigue en A'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->ids['a']['usuario'], 'company_id' => $this->a->id]);
        // Moverlo a B: 403
        $this->putJson('/api/users/'.$this->ids['a']['usuario'], ['company_id' => $this->b->id])->assertForbidden();

        // Ni el superadmin lo mueve con este endpoint
        $this->superadmin();
        $this->putJson('/api/users/'.$this->ids['a']['usuario'], ['company_id' => $this->b->id])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->ids['a']['usuario'], 'company_id' => $this->a->id]);
    }

    public function test_el_administrador_de_un_cliente_no_toca_al_superadmin_aunque_este_en_su_empresa(): void
    {
        $operador = $this->superadmin();
        $this->confinado();

        $this->putJson("/api/users/{$operador->id}", ['activo' => false])->assertForbidden();
        $this->putJson("/api/users/{$operador->id}", ['rol' => 'cajero', 'password' => 'otra-clave-123'])->assertForbidden();
        $this->deleteJson("/api/users/{$operador->id}")->assertForbidden();

        $fresco = $operador->fresh();
        $this->assertTrue($fresco->activo);
        $this->assertSame('admin', $fresco->rol);
        $this->assertTrue($fresco->esSuperadmin());
    }

    public function test_el_administrador_de_un_cliente_no_toca_a_un_usuario_sin_empresa(): void
    {
        // Una cuenta antigua sin company_id no es «de otra empresa» para el middleware, pero tampoco es de este cliente
        $antiguo = User::factory()->create(['rol' => 'contador', 'activo' => true, 'company_id' => null, 'es_superadmin' => false]);
        $this->confinado();

        $this->putJson("/api/users/{$antiguo->id}", ['name' => 'Cambiado', 'password' => 'otra-clave-123'])->assertForbidden();
        $this->deleteJson("/api/users/{$antiguo->id}")->assertForbidden();
        $this->assertNotSame('Cambiado', $antiguo->fresh()->name);
    }

    // ------------------------------------------------------------------ migración y sembrado

    public function test_la_migracion_deja_como_superadmin_a_los_administradores_que_ya_existian(): void
    {
        DB::table('users')->update(['es_superadmin' => false]);
        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true, 'company_id' => $this->a->id]);
        $contador = User::factory()->create(['rol' => 'contador', 'activo' => true, 'company_id' => $this->a->id]);
        $cajero = User::factory()->create(['rol' => 'cajero', 'activo' => true, 'company_id' => $this->b->id]);

        (require database_path('migrations/2026_10_30_000000_add_es_superadmin_to_users.php'))->up();

        $this->assertTrue($admin->fresh()->esSuperadmin());
        $this->assertFalse($contador->fresh()->esSuperadmin());
        $this->assertFalse($cajero->fresh()->esSuperadmin());
    }

    public function test_el_usuario_nuevo_no_es_superadmin_por_omision(): void
    {
        $u = User::factory()->create(['rol' => 'admin', 'company_id' => $this->a->id]);

        $this->assertFalse($u->fresh()->esSuperadmin());
        $this->assertTrue($u->fresh()->estaConfinadoAEmpresa());
        $this->assertFalse(User::factory()->create(['company_id' => null])->estaConfinadoAEmpresa());
    }

    public function test_es_superadmin_no_se_asigna_en_masa(): void
    {
        // Defensa en profundidad: aunque un controlador mande el arreglo de la petición completo a create(), el campo no entra
        $this->assertFalse((new User)->isFillable('es_superadmin'));
        $u = User::create(['name' => 'X', 'email' => 'x.masa@cliente.test', 'password' => 'clave-segura-1', 'company_id' => $this->a->id, 'es_superadmin' => true]);

        $this->assertFalse($u->fresh()->esSuperadmin());
    }

    public function test_el_administrador_del_sembrado_es_superadmin_y_la_contadora_no(): void
    {
        Company::query()->delete();
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::where('email', 'admin@demo.com')->firstOrFail()->esSuperadmin());
        $this->assertFalse(User::where('email', 'contador@demo.com')->firstOrFail()->esSuperadmin());
    }
}
