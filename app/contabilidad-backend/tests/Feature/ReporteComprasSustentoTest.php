<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.2: reporte "Compras por sustento tributario": agrupado por sustento y por tipo de
 * comprobante, con cantidad de comprobantes, subtotal 15%, subtotal 0%, IVA, total y total general.
 */
class ReporteComprasSustentoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedorA;
    private Contact $proveedorB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Reporte Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedorA = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor A',
        ]);
        $this->proveedorB = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011224001', 'razon_social' => 'Proveedor B',
        ]);
        Sanctum::actingAs(User::factory()->create());

        // Tres compras en dos sustentos (06 inventario, 01 servicios), dentro de septiembre de 2026
        // 1) Factura, 06: 2 x 55 con descuento de 10 -> base 100 al 15%, IVA 15
        $this->compra($this->proveedorA, '001-001-000000501', '2026-09-05', '06', 'factura', [
            ['codigo_principal' => 'A', 'cantidad' => 2, 'precio_unitario' => 55, 'descuento' => 10, 'tarifa' => 15],
        ], 100, 15);
        // 2) Compra antigua sin tipo guardado (cuenta como factura), 06: 200 al 15% y 50 al 0%
        $this->compra($this->proveedorA, '001-001-000000502', '2026-09-12', '06', null, [
            ['codigo_principal' => 'B', 'cantidad' => 4, 'precio_unitario' => 50, 'tarifa' => 15],
            ['codigo_principal' => 'C', 'cantidad' => 1, 'precio_unitario' => 50, 'tarifa' => 0],
        ], 250, 30);
        // 3) Liquidación de compra, 01: 80 al 0%
        $this->compra($this->proveedorB, '001-001-000000503', '2026-09-20', '01', 'liquidacion_compra', [
            ['codigo_principal' => 'D', 'cantidad' => 1, 'precio_unitario' => 80, 'tarifa' => 0],
        ], 80, 0);
        // Fuera del rango que se pide en las pruebas (agosto)
        $this->compra($this->proveedorA, '001-001-000000490', '2026-08-01', '02', 'nota_venta', [
            ['codigo_principal' => 'E', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 0],
        ], 10, 0);
    }

    public function test_agrupa_por_sustento_y_por_tipo_de_comprobante_con_el_total_general(): void
    {
        $r = $this->getJson($this->url(['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->assertOk()->json();

        $this->assertSame([
            ['codigo' => '01', 'nombre' => 'Crédito Tributario para declaración de IVA (servicios y bienes distintos de inventario)',
                'comprobantes' => 1, 'subtotal_15' => 0.0, 'subtotal_0' => 80.0, 'iva' => 0.0, 'total' => 80.0],
            ['codigo' => '06', 'nombre' => 'Inventario - Crédito Tributario para declaración de IVA',
                'comprobantes' => 2, 'subtotal_15' => 300.0, 'subtotal_0' => 50.0, 'iva' => 45.0, 'total' => 395.0],
        ], $this->numeros($r['por_sustento']));

        $this->assertSame([
            ['codigo' => '01', 'nombre' => 'Factura',
                'comprobantes' => 2, 'subtotal_15' => 300.0, 'subtotal_0' => 50.0, 'iva' => 45.0, 'total' => 395.0],
            ['codigo' => '03', 'nombre' => 'Liquidación de compra',
                'comprobantes' => 1, 'subtotal_15' => 0.0, 'subtotal_0' => 80.0, 'iva' => 0.0, 'total' => 80.0],
        ], $this->numeros($r['por_tipo']));

        $this->assertSame(
            ['comprobantes' => 3, 'subtotal_15' => 300.0, 'subtotal_0' => 130.0, 'iva' => 45.0, 'total' => 475.0],
            $this->numeros([$r['totales']])[0] ?? null,
        );
    }

    public function test_sin_fechas_incluye_todas_las_compras_y_cada_grupo_suma_el_total_general(): void
    {
        $r = $this->getJson($this->url())->assertOk()->json();

        $porCodigo = collect($r['por_sustento'])->keyBy('codigo');
        $this->assertSame(['01', '02', '06'], $porCodigo->keys()->all());
        $this->assertSame(1, $porCodigo['02']['comprobantes']);
        $this->assertEquals(10.0, $porCodigo['02']['total']);

        $this->assertSame(4, $r['totales']['comprobantes']);
        $this->assertEquals(485.0, $r['totales']['total']);
        // Ambas agrupaciones suman lo mismo que el total general
        $this->assertEquals($r['totales']['total'], round(collect($r['por_sustento'])->sum('total'), 2));
        $this->assertEquals($r['totales']['total'], round(collect($r['por_tipo'])->sum('total'), 2));
        $this->assertSame(['01', '02', '03'], collect($r['por_tipo'])->pluck('codigo')->all());
    }

    public function test_filtra_por_proveedor(): void
    {
        $r = $this->getJson($this->url(['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'contact_id' => $this->proveedorB->id]))
            ->assertOk()->json();

        $this->assertSame(['01'], collect($r['por_sustento'])->pluck('codigo')->all());
        $this->assertSame(1, $r['totales']['comprobantes']);
        $this->assertEquals(80.0, $r['totales']['total']);
    }

    public function test_exporta_a_excel_con_las_dos_secciones_y_el_total_general(): void
    {
        $resp = $this->get($this->url(['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'formato' => 'excel']))->assertOk();

        $this->assertStringContainsString('text/csv', (string) $resp->headers->get('content-type'));
        $this->assertStringContainsString('reporte-compras-sustento.csv', (string) $resp->headers->get('content-disposition'));

        $csv = $resp->getContent();
        $this->assertStringContainsString('"Por sustento tributario"', $csv);
        $this->assertStringContainsString('"Por tipo de comprobante"', $csv);
        $this->assertStringContainsString('"06","Inventario - Crédito Tributario para declaración de IVA","2","300.00","50.00","45.00","395.00"', $csv);
        $this->assertStringContainsString('"03","Liquidación de compra","1","0.00","80.00","0.00","80.00"', $csv);
        $this->assertStringContainsString('"","TOTAL GENERAL","3","300.00","130.00","45.00","475.00"', $csv);
    }

    public function test_exporta_a_pdf(): void
    {
        $resp = $this->get($this->url(['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'formato' => 'pdf']))->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $resp->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_exige_la_empresa(): void
    {
        $this->getJson('/api/reportes/compras-sustento')->assertStatus(422)->assertJsonValidationErrors(['company_id']);
    }

    // --------------------------------------------------------------- helpers

    private function url(array $extra = []): string
    {
        return '/api/reportes/compras-sustento?'.http_build_query(['company_id' => $this->company->id] + $extra);
    }

    private function compra(Contact $proveedor, string $numero, string $fecha, string $sustento, ?string $tipo, array $items, float $base, float $iva): Purchase
    {
        return Purchase::create([
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => $numero, 'fecha_emision' => $fecha, 'items' => $items,
            'sustento_tributario' => $sustento, 'tipo_comprobante' => $tipo,
            'total_sin_impuestos' => $base, 'total_impuesto' => $iva,
            'importe_total' => $base + $iva, 'saldo_pendiente' => $base + $iva,
        ]);
    }

    /** Deja las cifras como decimales y solo las columnas que se comparan. */
    private function numeros(array $filas): array
    {
        return array_map(function (array $f) {
            $out = [];
            foreach (['codigo', 'nombre'] as $k) {
                if (array_key_exists($k, $f)) {
                    $out[$k] = $f[$k];
                }
            }
            $out['comprobantes'] = (int) $f['comprobantes'];
            foreach (['subtotal_15', 'subtotal_0', 'iva', 'total'] as $k) {
                $out[$k] = (float) $f[$k];
            }

            return $out;
        }, $filas);
    }
}
