<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T4.3c: el plan de cuentas sugiere el código siguiente de cada nivel ("me sale la secuencia para yo poner solo 003")
 * y nunca deja dos cuentas con el mismo código en una empresa.
 *
 * El plan sembrado usa un solo dígito en el nivel 1 y 2 (1, 1.1) y dos dígitos desde el nivel 3 (1.1.01); las cuentas
 * nuevas siguen el relleno con ceros de sus hermanas.
 */
class SiguienteCodigoCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Plan SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->otra = Company::create([
            'ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        Cuentas::sembrar($this->otra->id);
        Sanctum::actingAs(User::factory()->create(['rol' => 'contador', 'activo' => true]));
    }

    // ------------------------------------------------------------- las 4 situaciones

    public function test_padre_con_hijas_sigue_la_secuencia_y_el_relleno_de_las_hermanas(): void
    {
        // 1.1 ACTIVO CORRIENTE tiene 1.1.01 … 1.1.11 en el plan sembrado (la 1.1.11 es Anticipos y préstamos a empleados, T7.1)
        $res = $this->siguiente('1.1')->assertOk();
        $res->assertJsonPath('codigo', '1.1.12')->assertJsonPath('padre', '1.1')->assertJsonPath('tipo', 'activo')
            ->assertJsonPath('padre_nombre', 'ACTIVO CORRIENTE')->assertJsonPath('hermanas', 11);
        $this->assertFalse(Account::where('company_id', $this->company->id)->where('codigo', '1.1.12')->exists());

        // Se crea con ese código y la siguiente sugerencia avanza
        $this->crear('1.1.12', 'Anticipos varios', 'activo')->assertCreated();
        $this->siguiente('1.1')->assertJsonPath('codigo', '1.1.13');
        // Los pasivos corrientes (2.1.01 … 2.1.11)
        $this->siguiente('2.1')->assertJsonPath('codigo', '2.1.12')->assertJsonPath('tipo', 'pasivo');
    }

    public function test_padre_sin_hijas_empieza_la_secuencia_y_las_hermanas_con_mas_digitos_mandan(): void
    {
        // 1.1.01 Caja no tiene subcuentas: la primera es 1.1.01.01
        $this->siguiente('1.1.01')->assertOk()->assertJsonPath('codigo', '1.1.01.01')->assertJsonPath('hermanas', 0)->assertJsonPath('nivel', 4);
        // Un nivel más abajo, sin hermanas, ya lleva tres dígitos
        $this->crear('1.1.01.01', 'Caja chica', 'activo')->assertCreated();
        $this->siguiente('1.1.01.01')->assertOk()->assertJsonPath('codigo', '1.1.01.01.001');

        // Con hermanas de tres dígitos (001, 002) la siguiente es 003, tal como las demás
        $this->crear('1.1.01.01.001', 'Caja chica A', 'activo')->assertCreated();
        $this->crear('1.1.01.01.002', 'Caja chica B', 'activo')->assertCreated();
        $this->siguiente('1.1.01.01')->assertOk()->assertJsonPath('codigo', '1.1.01.01.003')->assertJsonPath('hermanas', 2);
    }

    public function test_nivel_superior_sin_padre_sigue_el_ultimo_numero(): void
    {
        // 1 ACTIVO, 2 PASIVO, 3 PATRIMONIO, 4 INGRESOS, 5 GASTOS
        $this->siguiente(null)->assertOk()->assertJsonPath('codigo', '6')->assertJsonPath('nivel', 1)->assertJsonPath('padre', null)->assertJsonPath('hermanas', 5);
        $this->siguiente('')->assertOk()->assertJsonPath('codigo', '6');

        // Al pasar de 9 el relleno crece sin repetir ni truncar
        $this->crear('9', 'OTROS', 'activo')->assertCreated();
        $this->siguiente(null)->assertJsonPath('codigo', '10');
        // La 5 GASTOS no tiene un 5.x directo (5.1.01 cuelga de 5): el siguiente nivel arranca en 5.1
        $this->siguiente('5')->assertOk()->assertJsonPath('codigo', '5.1');
    }

    public function test_los_huecos_no_se_rellenan_las_otras_empresas_no_cuentan_y_el_padre_debe_existir(): void
    {
        // Hermanas 01, 02 y 05 (hay un hueco por una cuenta eliminada): sigue el MAYOR + 1, no "cuántas hay + 1".
        // Se usa 1.3 porque 1.2 ACTIVO NO CORRIENTE ya viene sembrado con los activos fijos (T7.2)
        Account::create(['company_id' => $this->company->id, 'codigo' => '1.3', 'nombre' => 'OTROS ACTIVOS', 'tipo' => 'activo']);
        foreach (['01', '02', '05'] as $n) {
            Account::create(['company_id' => $this->company->id, 'codigo' => "1.3.$n", 'nombre' => "Cuenta $n", 'tipo' => 'activo']);
        }
        $this->siguiente('1.3')->assertOk()->assertJsonPath('codigo', '1.3.06')->assertJsonPath('hermanas', 3);

        // Lo que tenga otra empresa no cambia la sugerencia
        Account::create(['company_id' => $this->otra->id, 'codigo' => '1.3', 'nombre' => 'OTROS ACTIVOS', 'tipo' => 'activo']);
        Account::create(['company_id' => $this->otra->id, 'codigo' => '1.3.09', 'nombre' => 'Ajena', 'tipo' => 'activo']);
        $this->siguiente('1.3')->assertJsonPath('codigo', '1.3.06');
        $this->siguiente('1.3', $this->otra->id)->assertJsonPath('codigo', '1.3.10');

        // Un número que no es de la misma secuencia (1.3.A) no estorba; el padre inexistente o mal escrito da 422 en español
        Account::create(['company_id' => $this->company->id, 'codigo' => '1.3.A', 'nombre' => 'Letra', 'tipo' => 'activo']);
        $this->siguiente('1.3')->assertJsonPath('codigo', '1.3.06');
        $this->siguiente('9.9')->assertStatus(422)->assertJsonPath('message', 'La cuenta padre 9.9 no existe en este plan de cuentas.');
        $this->siguiente('abc')->assertStatus(422)->assertJsonPath('message', 'El código de la cuenta padre solo lleva números separados por puntos, por ejemplo 1.1.01.');
        $this->getJson('/api/accounts/siguiente-codigo')->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_avisa_cuando_la_cuenta_padre_ya_tiene_movimientos(): void
    {
        $caja = Account::where('company_id', $this->company->id)->where('codigo', '1.1.01')->firstOrFail();
        $this->siguiente('1.1.01')->assertJsonPath('advertencia', null);

        $e = \App\Models\JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-000001', 'fecha' => '2026-03-05',
            'concepto' => 'x', 'total_debe' => 5, 'total_haber' => 5, 'estado' => 'pendiente']);
        $e->lines()->create(['account_id' => $caja->id, 'debe' => 5, 'haber' => 0]);

        $res = $this->siguiente('1.1.01')->assertOk()->assertJsonPath('codigo', '1.1.01.01');
        $this->assertStringContainsString('ya tiene movimientos', $res->json('advertencia'));
    }

    // ------------------------------------------------------------- duplicados

    public function test_no_se_repite_un_codigo_dentro_de_la_empresa_pero_si_en_otra(): void
    {
        $this->crear('1.1.01', 'Otra caja', 'activo')->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe una cuenta con el código 1.1.01. Usa el código sugerido o elige otro.')
            ->assertJsonValidationErrors('codigo');
        $this->assertSame(1, Account::where('company_id', $this->company->id)->where('codigo', '1.1.01')->count());

        // La misma empresa con un código libre: bien; la otra empresa con un código que ya tiene la primera: bien
        $this->crear('1.1.12', 'Nueva', 'activo')->assertCreated();
        $this->postJson('/api/accounts', ['company_id' => $this->otra->id, 'codigo' => '1.1.12', 'nombre' => 'Propia', 'tipo' => 'activo'])->assertCreated();

        // Editar a un código que ya tiene otra cuenta: 422; conservar el propio: bien
        $id = Account::where('company_id', $this->company->id)->where('codigo', '1.1.12')->value('id');
        $this->putJson("/api/accounts/{$id}", ['company_id' => $this->company->id, 'codigo' => '1.1.02', 'nombre' => 'Nueva', 'tipo' => 'activo'])
            ->assertStatus(422)->assertJsonPath('message', 'Ya existe una cuenta con el código 1.1.02. Usa el código sugerido o elige otro.');
        $this->putJson("/api/accounts/{$id}", ['company_id' => $this->company->id, 'codigo' => '1.1.12', 'nombre' => 'Nueva renombrada', 'tipo' => 'activo'])->assertOk();
        $this->assertSame('Nueva renombrada', Account::find($id)->nombre);
    }

    public function test_con_cuenta_padre_el_codigo_debe_colgar_de_ella_y_ser_de_la_misma_empresa(): void
    {
        $padre = Account::where('company_id', $this->company->id)->where('codigo', '1.1')->firstOrFail();
        $ajena = Account::where('company_id', $this->otra->id)->where('codigo', '1.1')->firstOrFail();

        $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => '1.1.12', 'nombre' => 'Hija', 'tipo' => 'activo', 'parent_id' => $padre->id])
            ->assertCreated()->assertJsonPath('parent_id', $padre->id);

        $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => '2.1.99', 'nombre' => 'Mal colgada', 'tipo' => 'pasivo', 'parent_id' => $padre->id])
            ->assertStatus(422)->assertJsonPath('message', 'El código debe empezar con 1.1. porque la cuenta padre es 1.1 ACTIVO CORRIENTE.');
        $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => '1.1.13', 'nombre' => 'Padre ajeno', 'tipo' => 'activo', 'parent_id' => $ajena->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->assertFalse(Account::where('company_id', $this->company->id)->whereIn('codigo', ['2.1.99', '1.1.13'])->exists());
    }

    // ------------------------------------------------------------- helpers

    private function siguiente(?string $padre, ?int $companyId = null)
    {
        $q = ['company_id' => $companyId ?? $this->company->id];
        if ($padre !== null) {
            $q['padre'] = $padre;
        }

        return $this->getJson('/api/accounts/siguiente-codigo?'.http_build_query($q));
    }

    private function crear(string $codigo, string $nombre, string $tipo)
    {
        return $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo]);
    }
}
