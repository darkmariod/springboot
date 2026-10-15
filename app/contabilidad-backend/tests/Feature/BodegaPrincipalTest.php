<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 8 / B4: una empresa nueva no tenía ninguna bodega, así que Ajuste de inventario,
 * Fraccionamiento y Conversión mostraban el selector de bodega vacío y no se podían usar.
 * Hoy la empresa demo del sembrador y toda empresa que se crea con `Warehouse::crearPrincipal`
 * nacen con "Bodega principal" (B01, por defecto). No hay gancho global en Company::create.
 */
class BodegaPrincipalTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $ruc, string $nombre): Company
    {
        return Company::create([
            'ruc' => $ruc, 'razon_social' => $nombre, 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
    }

    public function test_el_sembrador_deja_a_la_empresa_demo_con_una_bodega_principal_por_defecto(): void
    {
        $this->seed(DatabaseSeeder::class);

        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $bodegas = Warehouse::where('company_id', $demo->id)->get();

        $this->assertCount(1, $bodegas);
        $this->assertSame('B01', $bodegas[0]->codigo);
        $this->assertSame('Bodega principal', $bodegas[0]->nombre);
        $this->assertTrue($bodegas[0]->por_defecto);
        $this->assertTrue($bodegas[0]->activa);
    }

    public function test_sembrar_dos_veces_no_duplica_la_bodega(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $this->assertSame(1, Warehouse::where('company_id', $demo->id)->count());
    }

    public function test_la_pantalla_ve_la_bodega_de_la_empresa_sembrada(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'admin@demo.com')->firstOrFail());

        $this->getJson('/api/warehouses?company_id='.$demo->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.nombre', 'Bodega principal')
            ->assertJsonPath('0.por_defecto', true);
    }

    public function test_crear_una_segunda_empresa_no_toca_la_bodega_de_la_primera(): void
    {
        $primera = $this->empresa('1791111111001', 'Primera SA');
        $bodega1 = Warehouse::crearPrincipal($primera);
        $bodega1->update(['nombre' => 'Bodega renombrada']);
        $antes = $bodega1->fresh();

        $segunda = $this->empresa('1792222222001', 'Segunda SA');
        $bodega2 = Warehouse::crearPrincipal($segunda);

        $this->assertNotSame($bodega1->id, $bodega2->id);
        $this->assertSame($segunda->id, $bodega2->company_id);
        $this->assertSame(1, Warehouse::where('company_id', $primera->id)->count());
        $this->assertSame(1, Warehouse::where('company_id', $segunda->id)->count());
        $this->assertTrue($bodega2->por_defecto);

        $despues = $bodega1->fresh();
        $this->assertSame('Bodega renombrada', $despues->nombre);
        $this->assertTrue($despues->por_defecto);
        $this->assertEquals($antes->updated_at, $despues->updated_at);
    }

    public function test_si_la_empresa_ya_tiene_bodega_no_se_crea_otra(): void
    {
        $empresa = $this->empresa('1793333333001', 'Con bodega SA');
        $propia = Warehouse::create(['company_id' => $empresa->id, 'codigo' => 'BG001', 'nombre' => 'BODEGA GENERAL', 'por_defecto' => true]);

        $devuelta = Warehouse::crearPrincipal($empresa->id);

        $this->assertSame($propia->id, $devuelta->id);
        $this->assertSame(1, Warehouse::where('company_id', $empresa->id)->count());
    }

    public function test_crear_una_empresa_directamente_no_crea_bodega_sola(): void
    {
        // Hay muchas pruebas que crean empresas a mano y arman su propia bodega: no hay gancho global
        $empresa = $this->empresa('1794444444001', 'Directa SA');

        $this->assertSame(0, Warehouse::where('company_id', $empresa->id)->count());
    }
}
