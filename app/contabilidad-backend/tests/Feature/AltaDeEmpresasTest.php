<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmissionPoint;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T11.6c: dar de alta una empresa cliente (API `POST /api/companies` y comando `empresa:crear`) con todo lo que necesita para
 * trabajar el primer día, en una sola transacción, y con su primer administrador confinado a ella.
 */
class AltaDeEmpresasTest extends AislamientoTestCase
{
    private const CLAVE = 'Clave-Inicial-2026';

    /** @var list<string> */
    private array $bitacora = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 10:00:00');
        Log::listen(fn ($e) => $this->bitacora[] = json_encode([$e->level, $e->message, $e->context], JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'ruc' => '1792233445001', 'razon_social' => 'Cliente Nuevo S.A.', 'nombre_comercial' => 'Nuevo', 'dir_matriz' => 'Av. Nueva 100 y Calle 2',
            'admin_nombre' => 'Ana Administradora', 'admin_email' => 'ana@clientenuevo.test', 'admin_password' => self::CLAVE,
            'admin_password_confirmation' => self::CLAVE,
        ];
    }

    private function sinNada(string $ruc = '1792233445001', string $correo = 'ana@clientenuevo.test'): void
    {
        $this->assertDatabaseMissing('companies', ['ruc' => $ruc]);
        $this->assertDatabaseMissing('users', ['email' => $correo]);
        $this->assertSame(2, Company::count());
    }

    private function nada(): void
    {
        // Nada de la empresa a medias: ni cuentas, ni bodega, ni punto de emisión fuera de las dos empresas de la prueba
        $this->assertSame(2, Account::distinct()->count('company_id'));
        $this->assertSame(2, Warehouse::distinct()->count('company_id'));
        $this->assertSame(2, EmissionPoint::distinct()->count('company_id'));
    }

    // ------------------------------------------------------------------ lo que crea

    public function test_el_superadmin_crea_la_empresa_con_todo_lo_que_necesita(): void
    {
        $this->superadmin();

        $r = $this->postJson('/api/companies', $this->datos())->assertCreated();

        $empresa = Company::where('ruc', '1792233445001')->firstOrFail();
        $this->assertSame('Cliente Nuevo S.A.', $empresa->razon_social);
        $this->assertSame('basico', $empresa->plan);
        $this->assertSame('2027-10-30', $empresa->plan_vence->toDateString());      // hoy + 365 días
        $this->assertSame(1, (int) $empresa->ambiente);                              // pruebas del SRI
        $this->assertSame('001', $empresa->estab);

        // Plan de cuentas, bodega principal y punto de emisión 001-001
        $this->assertGreaterThan(20, Account::where('company_id', $empresa->id)->count());
        $this->assertSame(Account::where('company_id', $this->a->id)->count() > 0, Account::where('company_id', $empresa->id)->exists());
        $bodega = Warehouse::where('company_id', $empresa->id)->get();
        $this->assertCount(1, $bodega);
        $this->assertTrue((bool) $bodega[0]->por_defecto);
        $punto = EmissionPoint::where('company_id', $empresa->id)->get();
        $this->assertCount(1, $punto);
        $this->assertSame(['001', '001', 1], [$punto[0]->estab, $punto[0]->punto, (int) $punto[0]->secuencial]);

        // Su primer administrador
        $admin = User::where('email', 'ana@clientenuevo.test')->firstOrFail();
        $this->assertSame('admin', $admin->rol);
        $this->assertTrue($admin->activo);
        $this->assertSame($empresa->id, $admin->company_id);
        $this->assertFalse($admin->esSuperadmin());
        $this->assertTrue($admin->estaConfinadoAEmpresa());
        $this->assertTrue(Hash::check(self::CLAVE, $admin->password));

        // La respuesta trae la empresa y el correo del administrador, nunca la clave (ni su cifrado)
        $r->assertJsonPath('company.id', $empresa->id)->assertJsonPath('admin.email', 'ana@clientenuevo.test')->assertJsonPath('admin.rol', 'admin');
        $this->assertStringNotContainsString(self::CLAVE, $r->getContent());
        $this->assertStringNotContainsString($admin->password, $r->getContent());
        $this->assertArrayNotHasKey('password', $r->json('admin'));
    }

    public function test_el_plan_y_el_vencimiento_indicados_se_respetan(): void
    {
        $this->superadmin();

        $this->postJson('/api/companies', $this->datos(['plan' => 'negocio', 'plan_vence' => '2027-05-05', 'ambiente' => 2]))->assertCreated();

        $empresa = Company::where('ruc', '1792233445001')->firstOrFail();
        $this->assertSame('negocio', $empresa->plan);
        $this->assertSame('2027-05-05', $empresa->plan_vence->toDateString());
        $this->assertSame(2, (int) $empresa->ambiente);
        $this->assertContains('facturacion_sri', $empresa->features());
    }

    public function test_el_administrador_nuevo_ingresa_y_ve_solo_su_empresa(): void
    {
        $this->superadmin();
        // Plan Negocio: incluye la gestión de usuarios (el Básico no)
        $nueva = $this->postJson('/api/companies', $this->datos(['plan' => 'negocio']))->assertCreated()->json('company.id');

        // Entra de verdad, con su correo y su clave
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $token = $this->postJson('/api/login', ['email' => 'ana@clientenuevo.test', 'password' => self::CLAVE])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('rol', 'admin')->assertJsonPath('es_superadmin', false)->assertJsonPath('company_id', $nueva);
        $this->assertSame([$nueva], array_column($this->getJson('/api/companies')->assertOk()->json(), 'id'));
        $this->getJson("/api/companies/$nueva/plan")->assertOk()->assertJsonPath('plan', 'negocio');

        // No llega a ninguna otra empresa ni a su información
        $this->getJson("/api/companies/{$this->a->id}")->assertForbidden();
        $this->getJson("/api/contacts?company_id={$this->a->id}")->assertForbidden();
        $this->getJson('/api/fixed-assets/'.$this->ids['a']['activo_fijo'])->assertForbidden();
        $this->assertStringNotContainsString('MARCA-A', $this->getJson('/api/contacts')->assertOk()->getContent());
        $this->assertStringNotContainsString('MARCA-A', $this->getJson('/api/users')->assertOk()->getContent());

        // Trabaja en la suya: el plan de cuentas ya está, y puede crear usuarios solo en su empresa
        $this->assertGreaterThan(20, count($this->getJson("/api/accounts?company_id=$nueva")->assertOk()->json()));
        $this->postJson('/api/users', ['name' => 'Caja 1', 'email' => 'caja1@clientenuevo.test', 'password' => 'clave-segura-1', 'rol' => 'cajero'])->assertCreated()->assertJsonPath('company_id', $nueva);
        // No se mejora el plan ni da de alta otra empresa
        $this->postJson("/api/companies/$nueva/plan", ['plan' => 'completo'])->assertForbidden();
        $this->postJson('/api/companies', $this->datos(['ruc' => '1799999999001', 'admin_email' => 'otra@clientenuevo.test']))->assertForbidden();
        $this->assertSame('negocio', Company::findOrFail($nueva)->plan);
    }

    public function test_la_empresa_vacia_pasa_los_chequeos_contable_e_inventario(): void
    {
        $this->superadmin();
        $nueva = $this->postJson('/api/companies', $this->datos())->assertCreated()->json('company.id');

        $this->artisan('contable:chequeo', ['--company' => $nueva, '--estricto' => true])->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $nueva])->assertExitCode(0);
    }

    public function test_crear_una_empresa_no_toca_a_las_demas(): void
    {
        $this->superadmin();
        $antes = [Account::where('company_id', $this->a->id)->count(), Account::where('company_id', $this->b->id)->count(), User::count() + 1];

        $this->postJson('/api/companies', $this->datos())->assertCreated();

        $this->assertSame($antes[0], Account::where('company_id', $this->a->id)->count());
        $this->assertSame($antes[1], Account::where('company_id', $this->b->id)->count());
        $this->assertSame($antes[2], User::count());
        $this->assertSame('Empresa B SA', $this->b->fresh()->razon_social);
    }

    // ------------------------------------------------------------------ quién puede

    public function test_solo_el_superadmin_da_de_alta_empresas(): void
    {
        foreach (['admin', 'contador', 'cajero'] as $rol) {
            $this->confinado($rol);
            $this->postJson('/api/companies', $this->datos())->assertForbidden();
        }
        $this->sinEmpresa('admin');
        $this->postJson('/api/companies', $this->datos())->assertForbidden();

        $this->sinNada();
    }

    public function test_sin_sesion_no_se_da_de_alta_nada(): void
    {
        $this->postJson('/api/companies', $this->datos())->assertUnauthorized();
        $this->sinNada();
    }

    // ------------------------------------------------------------------ validación

    /** @return array<string,array{array,string}> */
    public static function datosInvalidos(): array
    {
        return [
            'RUC de 12 dígitos' => [['ruc' => '179223344500'], 'ruc'],
            'RUC de 14 dígitos' => [['ruc' => '17922334450012'], 'ruc'],
            'RUC con letras' => [['ruc' => '17922334450AB'], 'ruc'],
            'RUC repetido' => [['ruc' => '1790000000001'], 'ruc'],
            'sin razón social' => [['razon_social' => ''], 'razon_social'],
            'sin dirección' => [['dir_matriz' => ''], 'dir_matriz'],
            'plan inexistente' => [['plan' => 'corporativo'], 'plan'],
            'vencimiento que no es fecha' => [['plan_vence' => 'ayer'], 'plan_vence'],
            'ambiente inválido' => [['ambiente' => 3], 'ambiente'],
            'sin nombre de administrador' => [['admin_nombre' => ''], 'admin_nombre'],
            'correo inválido' => [['admin_email' => 'no-es-correo'], 'admin_email'],
            'correo repetido' => [['admin_email' => 'usuario.a@empresa.test'], 'admin_email'],
            'contraseña de 9 caracteres' => [['admin_password' => 'Abcdef123', 'admin_password_confirmation' => 'Abcdef123'], 'admin_password'],
            'sin contraseña' => [['admin_password' => '', 'admin_password_confirmation' => ''], 'admin_password'],
            'contraseñas distintas' => [['admin_password_confirmation' => 'Otra-Clave-2026'], 'admin_password_confirmation'],
        ];
    }

    #[DataProvider('datosInvalidos')]
    public function test_los_datos_invalidos_se_rechazan_y_no_se_crea_nada(array $cambio, string $campo): void
    {
        $this->superadmin();
        $antes = [Company::count(), User::count()];

        $r = $this->postJson('/api/companies', $cambio + $this->datos())->assertStatus(422)->assertJsonValidationErrors($campo);

        $this->assertSame($antes, [Company::count(), User::count()]);
        $this->nada();
        $this->assertStringNotContainsString(self::CLAVE, $r->getContent());
    }

    public function test_la_confirmacion_de_la_contrasena_es_opcional_en_la_api(): void
    {
        $this->superadmin();
        $datos = $this->datos();
        unset($datos['admin_password_confirmation']);

        $this->postJson('/api/companies', $datos)->assertCreated();
    }

    // ------------------------------------------------------------------ todo o nada

    /** @return array<string,array{string}> */
    public static function pasosQueFallan(): array
    {
        return [
            'al crear el punto de emisión (después de la empresa, las cuentas y la bodega)' => ['eloquent.creating: '.EmissionPoint::class],
            'al crear el administrador (el último paso)' => ['eloquent.saving: '.User::class],
        ];
    }

    #[DataProvider('pasosQueFallan')]
    public function test_si_algo_falla_a_medias_no_queda_nada(string $evento): void
    {
        $this->superadmin();
        $antes = [Company::count(), User::count(), AuditLog::count() > 0];
        Event::listen($evento, fn () => throw new \RuntimeException('falla simulada con '.self::CLAVE.' dentro'));

        try {
            $r = $this->postJson('/api/companies', $this->datos());
        } finally {
            Event::forget($evento);
        }

        $r->assertStatus(500)->assertJsonPath('message', 'No se pudo crear la empresa. No se guardó nada.');
        $this->assertSame($antes[0], Company::count());
        $this->assertSame($antes[1], User::count());
        $this->sinNada();
        $this->nada();
        // Ni la respuesta ni el log dicen la clave (ni la de la falla simulada, que la traía en su mensaje)
        $this->assertStringNotContainsString(self::CLAVE, $r->getContent());
        $this->assertStringNotContainsString(self::CLAVE, implode("\n", $this->bitacora));
        $this->assertStringContainsString('Alta de empresa fallida', implode("\n", $this->bitacora));
    }

    public function test_despues_de_una_falla_se_puede_volver_a_intentar_con_los_mismos_datos(): void
    {
        $this->superadmin();
        $evento = 'eloquent.creating: '.EmissionPoint::class;
        Event::listen($evento, fn () => throw new \RuntimeException('falla simulada'));
        $this->postJson('/api/companies', $this->datos())->assertStatus(500);
        Event::forget($evento);

        $this->postJson('/api/companies', $this->datos())->assertCreated();

        $this->assertSame(3, Company::count());
    }

    public function test_la_clave_no_se_guarda_en_la_bitacora_de_auditoria(): void
    {
        $this->superadmin();
        $this->postJson('/api/companies', $this->datos())->assertCreated();

        $auditoria = AuditLog::all()->map(fn ($f) => json_encode($f->toArray()))->implode("\n");
        $this->assertStringNotContainsString(self::CLAVE, $auditoria);
        $this->assertStringNotContainsString(self::CLAVE, implode("\n", $this->bitacora));
    }

    // ------------------------------------------------------------------ comando empresa:crear

    private function comando(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('empresa:crear', $opciones + [
            '--ruc' => '1792233445001', '--razon' => 'Cliente Nuevo S.A.', '--dir' => 'Av. Nueva 100', '--plan' => 'negocio',
            '--vence' => '2027-03-15', '--admin-nombre' => 'Ana Administradora', '--admin-email' => 'ana@clientenuevo.test',
        ]);
    }

    public function test_el_comando_crea_la_misma_empresa_pidiendo_la_clave_sin_mostrarla(): void
    {
        $this->comando()
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres; no se muestra)', self::CLAVE)
            ->expectsQuestion('Repite la contraseña', self::CLAVE)
            ->expectsOutputToContain('Empresa creada')
            ->doesntExpectOutputToContain(self::CLAVE)
            ->assertExitCode(0);

        $empresa = Company::where('ruc', '1792233445001')->firstOrFail();
        $this->assertSame(['negocio', '2027-03-15'], [$empresa->plan, $empresa->plan_vence->toDateString()]);
        $this->assertGreaterThan(20, Account::where('company_id', $empresa->id)->count());
        $this->assertSame(1, Warehouse::where('company_id', $empresa->id)->count());
        $this->assertSame(1, EmissionPoint::where('company_id', $empresa->id)->count());
        $admin = User::where('email', 'ana@clientenuevo.test')->firstOrFail();
        $this->assertSame([$empresa->id, 'admin', true, false], [$admin->company_id, $admin->rol, $admin->activo, $admin->esSuperadmin()]);
        $this->assertTrue(Hash::check(self::CLAVE, $admin->password));
        $this->assertStringNotContainsString(self::CLAVE, implode("\n", $this->bitacora));
    }

    public function test_el_comando_pregunta_lo_que_falta(): void
    {
        $this->artisan('empresa:crear')
            ->expectsQuestion('RUC (13 dígitos)', '1792233445001')
            ->expectsQuestion('Razón social', 'Cliente Nuevo S.A.')
            ->expectsQuestion('Dirección de la matriz', 'Av. Nueva 100')
            ->expectsQuestion('Nombre del administrador', 'Ana Administradora')
            ->expectsQuestion('Correo del administrador', 'ana@clientenuevo.test')
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres; no se muestra)', self::CLAVE)
            ->expectsQuestion('Repite la contraseña', self::CLAVE)
            ->assertExitCode(0);

        $empresa = Company::where('ruc', '1792233445001')->firstOrFail();
        $this->assertSame('basico', $empresa->plan);
        $this->assertSame('2027-10-30', $empresa->plan_vence->toDateString());
    }

    public function test_el_comando_rechaza_un_ruc_repetido_sin_pedir_la_clave(): void
    {
        $this->comando(['--ruc' => '1790000000001'])
            ->expectsOutputToContain('Ya existe una empresa con ese RUC.')
            ->assertExitCode(1);

        $this->sinNada();
    }

    public function test_el_comando_rechaza_una_clave_debil_o_distinta(): void
    {
        $this->comando()
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres; no se muestra)', 'corta123')
            ->expectsQuestion('Repite la contraseña', 'corta123')
            ->expectsOutputToContain('al menos 10 caracteres')
            ->doesntExpectOutputToContain('corta123')
            ->assertExitCode(1);
        $this->comando()
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres; no se muestra)', self::CLAVE)
            ->expectsQuestion('Repite la contraseña', 'Otra-Clave-2026')
            ->expectsOutputToContain('no coinciden')
            ->assertExitCode(1);

        $this->sinNada();
        $this->nada();
    }

    public function test_el_comando_sin_interaccion_no_acepta_la_clave_por_argumento(): void
    {
        $this->comando(['--no-interaction' => true])
            ->expectsOutputToContain('se pide por teclado')
            ->assertExitCode(1);

        $this->sinNada();
    }

    public function test_el_comando_deja_todo_o_nada_cuando_algo_falla(): void
    {
        $evento = 'eloquent.creating: '.EmissionPoint::class;
        Event::listen($evento, fn () => throw new \RuntimeException('falla simulada'));
        try {
            $this->comando()
                ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres; no se muestra)', self::CLAVE)
                ->expectsQuestion('Repite la contraseña', self::CLAVE)
                ->expectsOutputToContain('No se pudo crear la empresa. No se guardó nada.')
                ->doesntExpectOutputToContain(self::CLAVE)
                ->assertExitCode(1);
        } finally {
            Event::forget($evento);
        }

        $this->sinNada();
        $this->nada();
        $this->assertStringNotContainsString(self::CLAVE, implode("\n", $this->bitacora));
    }
}
