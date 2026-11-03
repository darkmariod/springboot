<?php

namespace Tests\Feature\Reuniones;

use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmissionPoint;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\AislamientoTestCase;

/**
 * Requisitos de plataforma para vender HasReset a varios clientes (agregados el 30 de octubre de 2026):
 * aislamiento entre empresas, planes controlados por quien opera la plataforma y alta de empresas nuevas.
 * Usa `AislamientoTestCase`: dos empresas, A y B, cada una con sus registros marcados con «MARCA-A» y «MARCA-B».
 */
#[Group('reuniones')]
class PlataformaTest extends AislamientoTestCase
{
    private const NEGADO = 'No tienes acceso a esa empresa.';

    // ============================================================ R25 · aislamiento

    /**
     * Pedido por: PLAT
     * Pedido: «Un cliente de HasReset no puede ver los datos de otro cliente, por ninguna pantalla.»
     * Se prueba: el administrador de la empresa A ve sus datos y ninguna marca de B en 12 listas; pedir las de B con su company_id da 403; el operador de la plataforma sí ve las de B.
     */
    #[Group('reuniones')]
    #[Group('R25')]
    public function test_R25_un_cliente_no_ve_los_datos_de_otro_cliente(): void
    {
        $listas = ['contacts', 'products', 'invoices', 'purchases', 'warehouses', 'banks', 'accounts', 'employees', 'fixed-assets', 'journal', 'users', 'credit-notes'];

        // Act + Assert: el administrador de A, con su empresa y sin pedir ninguna
        $this->confinado('admin');
        foreach ($listas as $lista) {
            $propia = $this->getJson("/api/{$lista}")->assertOk()->getContent();
            $this->assertStringNotContainsString('MARCA-B', $propia, "/api/{$lista} muestra datos de la otra empresa");
            $conCompany = $this->getJson("/api/{$lista}?company_id={$this->a->id}")->assertOk()->getContent();
            $this->assertStringNotContainsString('MARCA-B', $conCompany, "/api/{$lista}?company_id=A muestra datos de la otra empresa");
            // Assert: pedir la empresa B por su company_id se niega
            $this->getJson("/api/{$lista}?company_id={$this->b->id}")->assertForbidden()->assertJsonPath('message', self::NEGADO);
        }
        $this->assertStringContainsString('Cliente MARCA-A', $this->getJson('/api/contacts')->getContent(), 'Sí ve lo suyo');
        $this->assertStringContainsString('Producto MARCA-A', $this->getJson('/api/products')->getContent());
        $this->assertSame([$this->a->id], array_column($this->getJson('/api/companies')->assertOk()->json(), 'id'), 'La lista de empresas trae solo la suya');

        // Assert: el operador de la plataforma (superadmin) sí puede ver la empresa B
        $this->superadmin();
        $this->assertStringContainsString('Cliente MARCA-B', $this->getJson("/api/contacts?company_id={$this->b->id}")->assertOk()->getContent());
    }

    /**
     * Pedido por: PLAT
     * Pedido: «Y tampoco puede tocar nada del otro: ni cambiar, ni borrar, ni anular, ni crear cosas a nombre de otra empresa.»
     * Se prueba: editar, borrar, anular, imprimir y crear con datos de B desde A da 403 sin cambiar nada; un id que no existe responde lo mismo (no revela qué existe).
     */
    #[Group('reuniones')]
    #[Group('R25')]
    public function test_R25_un_cliente_no_puede_tocar_los_datos_de_otro_cliente(): void
    {
        $this->confinado('admin');
        $contactoB = $this->ids['b']['contacto'];
        $facturaB = $this->ids['b']['factura'];

        // Act + Assert: cada intento sobre un registro de B se niega
        $this->putJson("/api/contacts/{$contactoB}", ['razon_social' => 'Cambiado por A'])->assertForbidden();
        $this->deleteJson("/api/products/{$this->ids['b']['producto']}")->assertForbidden();
        $this->postJson("/api/invoices/{$facturaB}/anular")->assertForbidden();
        $this->getJson("/api/invoices/{$facturaB}/ride")->assertForbidden();
        $this->getJson("/api/fixed-assets/{$this->ids['b']['activo_fijo']}")->assertForbidden();
        $this->postJson("/api/payables/{$this->ids['b']['compra']}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 10]]])->assertForbidden();
        // Assert: crear en nombre de B, o con un registro de B dentro, también
        $this->postJson('/api/products', ['company_id' => $this->b->id, 'codigo' => 'X', 'descripcion' => 'X', 'tipo' => 'bien'])->assertForbidden();
        $this->postJson('/api/invoices', [
            'company_id' => $this->a->id, 'contact_id' => $contactoB, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'P-A', 'descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertForbidden();

        // Assert: un id que no existe responde igual que uno ajeno (no se puede explorar qué existe)
        $this->putJson('/api/contacts/99999999', ['razon_social' => 'X'])->assertForbidden()->assertJsonPath('message', self::NEGADO);

        // Assert: nada de B cambió
        $this->assertSame('Cliente MARCA-B', DB::table('contacts')->where('id', $contactoB)->value('razon_social'));
        $this->assertSame(1, DB::table('products')->where('id', $this->ids['b']['producto'])->count());
        $this->assertSame('emitida', DB::table('invoices')->where('id', $facturaB)->value('estado'));
        $this->assertSame(0, DB::table('invoices')->where('company_id', $this->a->id)->where('contact_id', $contactoB)->count());
    }

    // ============================================================ R26 · planes

    /**
     * Pedido por: PLAT
     * Pedido: «El administrador de un cliente no puede cambiarse el plan; solo yo, desde la plataforma.»
     * Se prueba: el administrador del cliente recibe 403 al cambiar el plan y 422 si lo intenta por la edición de la empresa; el superadmin lo cambia y fija el vencimiento.
     */
    #[Group('reuniones')]
    #[Group('R26')]
    public function test_R26_el_administrador_de_un_cliente_no_se_cambia_el_plan_pero_el_superadmin_si(): void
    {
        // Arrange: la empresa A con el plan Básico hasta el 31 de enero de 2027
        $this->a->update(['plan' => 'basico', 'plan_vence' => '2027-01-31']);

        // Act + Assert 1: el administrador del cliente intenta mejorarse el plan
        $this->confinado('admin');
        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo', 'plan_vence' => '2030-12-31'])->assertForbidden();
        $this->putJson("/api/companies/{$this->a->id}", ['plan' => 'completo'])->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->putJson("/api/companies/{$this->a->id}", ['plan_vence' => '2035-01-01'])->assertStatus(422)->assertJsonValidationErrors('plan_vence');
        $this->assertSame('basico', $this->a->fresh()->plan);
        $this->assertSame('2027-01-31', $this->a->fresh()->plan_vence->toDateString());
        // Assert 1b: el contador y el cajero tampoco
        foreach (['contador', 'cajero'] as $rol) {
            $this->confinado($rol);
            $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo'])->assertForbidden();
        }

        // Act + Assert 2: el superadmin sí cambia el plan y el vencimiento
        $this->superadmin();
        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'negocio', 'plan_vence' => '2028-06-30'])
            ->assertOk()->assertJsonPath('plan', 'negocio')->assertJsonPath('vence', '2028-06-30');
        $this->assertSame('negocio', $this->a->fresh()->plan);
        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'corporativo'])->assertStatus(422);
    }

    /**
     * Pedido por: PLAT
     * Pedido: «Si el plan de un cliente venció, el sistema tiene que bloquearle las funciones hasta que renueve.»
     * Se prueba: con el plan vencido ayer, contabilidad y ventas responden 402 «El plan de esta empresa venció»; al renovar el superadmin vuelven a responder 200.
     */
    #[Group('reuniones')]
    #[Group('R26')]
    public function test_R26_el_plan_vencido_bloquea_las_funciones_hasta_renovar(): void
    {
        // Arrange: el plan de A venció ayer
        $this->a->update(['plan' => 'completo', 'plan_vence' => now()->subDay()->toDateString()]);
        $this->confinado('admin');

        // Act + Assert 1: las funciones por plan responden 402 con el mensaje de renovación
        foreach (['cost-centers', 'journal', 'receivables', 'banks'] as $ruta) {
            $this->getJson("/api/{$ruta}")->assertStatus(402)
                ->assertJsonPath('message', 'El plan de esta empresa venció. Renueva para seguir usando el sistema.');
        }
        $this->assertTrue($this->getJson("/api/companies/{$this->a->id}/plan")->assertOk()->json('vencido'), 'El plan se muestra como vencido');

        // Act + Assert 2: el superadmin renueva y todo vuelve a funcionar
        $this->superadmin();
        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo', 'plan_vence' => now()->addYear()->toDateString()])->assertOk();
        $this->confinado('admin');
        $this->getJson('/api/cost-centers')->assertOk();
        $this->assertFalse($this->getJson("/api/companies/{$this->a->id}/plan")->json('vencido'));
    }

    /**
     * Pedido por: PLAT
     * Pedido: «Cada plan solo da lo que se pagó: si el plan no incluye contabilidad, esa parte no debe abrirse.»
     * Se prueba: con el plan Básico la contabilidad y la nómina responden 402 y la venta sigue disponible; al pasar a Completo se abren.
     */
    #[Group('reuniones')]
    #[Group('R26')]
    public function test_R26_cada_plan_abre_solo_los_modulos_que_incluye(): void
    {
        // Arrange: empresa A con el plan Básico (ventas y compras, sin contabilidad ni nómina)
        $this->a->update(['plan' => 'basico', 'plan_vence' => now()->addYear()->toDateString()]);
        $this->confinado('admin');

        // Act + Assert 1: la venta funciona; la contabilidad y la nómina no
        $this->getJson('/api/invoices')->assertOk();
        $this->getJson('/api/journal')->assertStatus(402)->assertJsonPath('message', 'Tu plan basico no incluye este módulo. Actualiza el plan para activarlo.');
        $this->getJson('/api/payrolls')->assertStatus(402);
        $this->getJson('/api/fixed-assets')->assertStatus(402);

        // Act + Assert 2: el superadmin sube el plan a Completo y se abren
        $this->superadmin();
        $this->postJson("/api/companies/{$this->a->id}/plan", ['plan' => 'completo', 'plan_vence' => now()->addYear()->toDateString()])->assertOk();
        $this->confinado('admin');
        $this->getJson('/api/journal')->assertOk();
        $this->getJson('/api/payrolls')->assertOk();
    }

    // ============================================================ R27 · alta de empresa

    /**
     * Pedido por: PLAT
     * Pedido: «Dar de alta una empresa nueva tiene que funcionar de una vez, con su plan de cuentas, y dejarle solo a su administrador.»
     * Se prueba: el superadmin crea la empresa; queda con plan de cuentas, bodega, punto 001-001 y un solo usuario (su administrador), que entra y ve solo lo suyo.
     */
    #[Group('reuniones')]
    #[Group('R27')]
    public function test_R27_el_alta_de_una_empresa_nueva_funciona_y_deja_solo_a_su_administrador(): void
    {
        // Act 1: el operador de la plataforma crea la empresa con el plan Negocio
        $this->superadmin();
        $clave = 'Clave-Inicial-2026';
        $r = $this->postJson('/api/companies', [
            'ruc' => '1792233445001', 'razon_social' => 'Cliente Nuevo S.A.', 'dir_matriz' => 'Av. Nueva 100 y Calle 2', 'plan' => 'negocio',
            'admin_nombre' => 'Ana Administradora', 'admin_email' => 'ana@clientenuevo.test', 'admin_password' => $clave, 'admin_password_confirmation' => $clave,
        ])->assertCreated();
        $nueva = $r->json('company.id');

        // Assert 1: nace con todo lo que necesita y la clave nunca se devuelve
        $this->assertGreaterThan(20, Account::where('company_id', $nueva)->count(), 'Trae su plan de cuentas');
        $this->assertSame(1, Warehouse::where('company_id', $nueva)->count());
        $this->assertSame(['001', '001'], [EmissionPoint::where('company_id', $nueva)->value('estab'), EmissionPoint::where('company_id', $nueva)->value('punto')]);
        $usuarios = User::where('company_id', $nueva)->get();
        $this->assertCount(1, $usuarios, 'Solo su administrador');
        $this->assertSame(['ana@clientenuevo.test', 'admin', false], [$usuarios[0]->email, $usuarios[0]->rol, $usuarios[0]->esSuperadmin()]);
        $this->assertStringNotContainsString($clave, $r->getContent());
        $this->artisan('contable:chequeo', ['--company' => $nueva, '--estricto' => true])->assertExitCode(0);

        // Act 2: la administradora nueva entra con su correo y su clave
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $token = $this->postJson('/api/login', ['email' => 'ana@clientenuevo.test', 'password' => $clave])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);

        // Assert 2: ve solo su empresa y no llega a las demás
        $this->getJson('/api/user')->assertOk()->assertJsonPath('rol', 'admin')->assertJsonPath('company_id', $nueva);
        $this->assertSame([$nueva], array_column($this->getJson('/api/companies')->assertOk()->json(), 'id'));
        $this->getJson("/api/contacts?company_id={$this->a->id}")->assertForbidden();
        $this->assertStringNotContainsString('MARCA-', $this->getJson('/api/contacts')->assertOk()->getContent());
        // Assert 3: un cliente no da de alta otras empresas
        $this->postJson('/api/companies', [
            'ruc' => '1799999999001', 'razon_social' => 'Otra', 'dir_matriz' => 'Av. 1', 'admin_nombre' => 'X', 'admin_email' => 'x@otra.test', 'admin_password' => $clave,
        ])->assertForbidden();
        $this->assertSame(0, Company::where('ruc', '1799999999001')->count());
    }
}
