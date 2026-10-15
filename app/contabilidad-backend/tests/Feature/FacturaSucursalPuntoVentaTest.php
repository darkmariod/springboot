<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmissionPoint;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F9-5: la pantalla de Sucursales contaba 3 facturas cuando había 4 porque la factura hecha desde el punto de venta no quedaba
 * asignada a ninguna sucursal: el punto de emisión no siempre trae su sucursal ligada (branch_id vacío) y la factura se quedaba sin ella.
 * Ahora la sucursal sale del establecimiento del punto de emisión.
 */
class FacturaSucursalPuntoVentaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Branch $matriz;
    private Branch $norte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Distribuidora Andina SA', 'dir_matriz' => 'Av. Amazonas N24-15, Quito',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->matriz = Branch::create(['company_id' => $this->company->id, 'estab' => '001', 'nombre' => 'Matriz', 'direccion' => 'Av. Amazonas N24-15, Quito', 'es_matriz' => true, 'activa' => true]);
        $this->norte = Branch::create(['company_id' => $this->company->id, 'estab' => '002', 'nombre' => 'Sucursal Norte', 'direccion' => 'Av. Eloy Alfaro 500, Quito', 'es_matriz' => false, 'activa' => true]);
        $this->cliente = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'María Pazmiño']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));
    }

    private function puntoDeEmision(string $estab, string $punto, ?Branch $sucursal = null): EmissionPoint
    {
        $ep = EmissionPoint::create(['company_id' => $this->company->id, 'estab' => $estab, 'punto' => $punto, 'nombre' => "Caja $estab-$punto", 'secuencial' => 1]);
        if ($sucursal) {
            $ep->forceFill(['branch_id' => $sucursal->id])->save();
        }

        return $ep;
    }

    private function facturar(?int $puntoId = null): Invoice
    {
        $this->postJson('/api/invoices', array_filter([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'efectivo', 'emission_point_id' => $puntoId,
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ], fn ($v) => $v !== null))->assertCreated();

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_la_factura_desde_un_punto_de_emision_sin_sucursal_ligada_toma_la_sucursal_de_su_establecimiento(): void
    {
        $punto = $this->puntoDeEmision('002', '003');                         // punto de venta de la sucursal norte, sin branch_id

        $factura = $this->facturar($punto->id);

        $this->assertSame('002-003', substr($factura->numero, 0, 7));
        $this->assertSame($this->norte->id, $factura->branch_id);
    }

    public function test_el_punto_de_emision_con_sucursal_ligada_manda(): void
    {
        $punto = $this->puntoDeEmision('001', '004', $this->norte);           // ligado a norte aunque su establecimiento sea el 001

        $this->assertSame($this->norte->id, $this->facturar($punto->id)->branch_id);
    }

    public function test_sin_punto_de_emision_la_factura_sigue_en_la_matriz(): void
    {
        $this->assertSame($this->matriz->id, $this->facturar()->branch_id);
    }

    public function test_un_punto_de_emision_de_un_establecimiento_sin_sucursal_no_rompe_la_factura(): void
    {
        $punto = $this->puntoDeEmision('009', '001');

        $factura = $this->facturar($punto->id);

        $this->assertNull($factura->branch_id);
        $this->assertSame('009-001', substr($factura->numero, 0, 7));
    }

    public function test_la_pantalla_de_sucursales_cuenta_las_cuatro_facturas(): void
    {
        $this->facturar();                                                    // matriz, sin punto
        $this->facturar($this->puntoDeEmision('001', '002', $this->matriz)->id);   // matriz, punto ligado
        $this->facturar($this->puntoDeEmision('001', '003')->id);             // matriz, punto SIN sucursal ligada
        $this->facturar($this->puntoDeEmision('002', '001')->id);             // norte, punto SIN sucursal ligada

        $filas = collect($this->getJson('/api/branches?company_id='.$this->company->id)->assertOk()->json())->keyBy('id');

        $this->assertSame(3, $filas[$this->matriz->id]['invoices_count']);
        $this->assertSame(1, $filas[$this->norte->id]['invoices_count']);
        $this->assertSame(4, Invoice::whereNotNull('branch_id')->count(), 'Ninguna factura se queda sin sucursal');
    }
}
