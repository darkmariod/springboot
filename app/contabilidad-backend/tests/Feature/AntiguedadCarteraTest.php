<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Antigüedad de la cartera (Cuentas por cobrar): con Carbon 3, `$hoy->diffInDays($pasado)` da negativo y toda la
 * cartera caía en el tramo "0-30". Los días se cuentan desde la emisión hasta hoy, nunca negativos.
 */
class AntiguedadCarteraTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Cartera Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function factura(string $numero, int $diasAtras, float $saldo, string $estado = 'emitida'): Invoice
    {
        return Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => $numero, 'items' => [],
            'total_sin_impuestos' => $saldo, 'total_impuesto' => 0, 'importe_total' => $saldo, 'forma_pago' => 'credito',
            'saldo_pendiente' => $saldo, 'estado' => $estado, 'fecha_emision' => now()->subDays($diasAtras),
        ]);
    }

    public function test_las_facturas_de_10_45_70_y_120_dias_caen_en_los_cuatro_tramos(): void
    {
        $this->factura('001-001-000000001', 10, 100);
        $this->factura('001-001-000000002', 45, 200);
        $this->factura('001-001-000000003', 70, 300);
        $this->factura('001-001-000000004', 120, 400);

        $r = $this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk();

        $porNumero = collect($r->json('cartera'))->keyBy('numero');
        $this->assertSame(10, $porNumero['001-001-000000001']['dias']);
        $this->assertSame('0-30', $porNumero['001-001-000000001']['tramo']);
        $this->assertSame(45, $porNumero['001-001-000000002']['dias']);
        $this->assertSame('31-60', $porNumero['001-001-000000002']['tramo']);
        $this->assertSame(70, $porNumero['001-001-000000003']['dias']);
        $this->assertSame('61-90', $porNumero['001-001-000000003']['tramo']);
        $this->assertSame(120, $porNumero['001-001-000000004']['dias']);
        $this->assertSame('90+', $porNumero['001-001-000000004']['tramo']);

        $this->assertEquals(
            ['0-30' => 100.0, '31-60' => 200.0, '61-90' => 300.0, '90+' => 400.0],
            $r->json('antiguedad'),
            'Cada factura va a su tramo con su saldo; antes todo caía en 0-30',
        );
        $this->assertEquals(1000.0, $r->json('total'));
    }

    public function test_los_limites_de_los_tramos_y_una_factura_de_hoy(): void
    {
        $this->factura('001-001-000000011', 0, 10);    // hoy
        $this->factura('001-001-000000012', 30, 20);   // último día del primer tramo
        $this->factura('001-001-000000013', 31, 30);   // primer día del segundo
        $this->factura('001-001-000000014', 60, 40);
        $this->factura('001-001-000000015', 61, 50);
        $this->factura('001-001-000000016', 90, 60);
        $this->factura('001-001-000000017', 91, 70);

        $r = $this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk();
        $porNumero = collect($r->json('cartera'))->keyBy('numero');

        $this->assertSame(0, $porNumero['001-001-000000011']['dias'], 'Una factura de hoy tiene 0 días, no negativo');
        $this->assertEquals(
            ['0-30' => 30.0, '31-60' => 70.0, '61-90' => 110.0, '90+' => 70.0],
            $r->json('antiguedad'),
        );
    }

    public function test_una_factura_anulada_o_sin_saldo_no_entra_en_la_antiguedad(): void
    {
        $this->factura('001-001-000000021', 120, 100);
        $this->factura('001-001-000000022', 120, 999, 'anulado');
        $this->factura('001-001-000000023', 120, 0);

        $r = $this->getJson('/api/receivables?company_id='.$this->company->id)->assertOk();

        $this->assertCount(1, $r->json('cartera'));
        $this->assertEquals(['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 100.0], $r->json('antiguedad'));
    }
}
