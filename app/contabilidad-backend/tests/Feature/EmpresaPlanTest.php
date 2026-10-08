<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T1.4: una empresa nueva nace con un plan que existe en config/planes.php,
 * así tiene menú y las rutas protegidas por plan no responden 402.
 */
class EmpresaPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_nueva_sin_plan_recibe_completo(): void
    {
        $empresa = $this->crear();

        $this->assertSame('completo', $empresa->fresh()->plan);
        $this->assertArrayHasKey($empresa->fresh()->plan, config('planes'));
        $this->assertContains('cartera', $empresa->fresh()->features());
        $this->assertContains('contabilidad', $empresa->fresh()->features());
    }

    public function test_empresa_nueva_puede_usar_rutas_protegidas_por_plan(): void
    {
        $empresa = $this->crear();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/receivables?company_id='.$empresa->id)->assertOk();
        $this->getJson("/api/companies/{$empresa->id}/plan")->assertOk()
            ->assertJsonPath('plan', 'completo')
            ->assertJsonPath('nombre', 'Completo');
    }

    public function test_un_plan_valido_elegido_se_respeta(): void
    {
        $this->assertSame('basico', $this->crear(['plan' => 'basico'])->fresh()->plan);
    }

    public function test_un_plan_que_no_existe_se_reemplaza_por_completo(): void
    {
        $this->assertSame('completo', $this->crear(['plan' => 'corporativo'])->fresh()->plan);
    }

    private function crear(array $extra = []): Company
    {
        return Company::create($extra + [
            'ruc' => '1791234567001',
            'razon_social' => 'Empresa Plan Test SA',
            'dir_matriz' => 'Av. Test 123',
        ]);
    }
}
