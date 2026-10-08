<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\StorePurchaseFromXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.3: sustentos 01 a 13 y que la compra importada por XML respete el sustento elegido.
 * El ATS usa el sustento y el tipo de comprobante reales de cada compra.
 */
class SustentosCompraTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Sustentos Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    // -------------------------------------------------------------- catálogo

    public function test_el_catalogo_trae_los_sustentos_del_01_al_13(): void
    {
        $respuesta = $this->getJson('/api/catalogos/sustentos')->assertOk()->json();
        // El código viaja siempre como texto ('10', '11'...): el selector de la pantalla compara con el valor guardado
        foreach ($respuesta as $sustento) {
            $this->assertIsString($sustento['value']);
        }
        $porCodigo = collect($respuesta)->mapWithKeys(fn ($s) => [$s['value'] => $s['label']]);

        foreach (range(1, 13) as $n) {
            $this->assertTrue($porCodigo->has(sprintf('%02d', $n)), 'Falta el sustento '.sprintf('%02d', $n));
        }
        $this->assertStringContainsString("Convenios de débito o recaudación para IFI's", $porCodigo['11']);
        $this->assertStringContainsString('Impuestos y retenciones presuntivos', $porCodigo['12']);
        $this->assertStringContainsString('Valores reconocidos por entidades del sector público a favor de sujetos pasivos', $porCodigo['13']);
        // Los nombres que ya existían no cambian
        $this->assertStringContainsString('Crédito Tributario para declaración de IVA', $porCodigo['01']);
        $this->assertStringContainsString('Distribución de Dividendos', $porCodigo['10']);
        $this->assertTrue($porCodigo->has('00'));
    }

    public function test_los_tipos_de_comprobante_que_se_eligen_al_registrar_una_compra(): void
    {
        $this->getJson('/api/catalogos/tipos-comprobante-compra')->assertOk()->assertExactJson([
            ['value' => 'factura', 'label' => 'Factura'],
            ['value' => 'nota_venta', 'label' => 'Nota de venta'],
        ]);
    }

    // ---------------------------------------------------- importación por XML

    public function test_la_importacion_por_xml_guarda_el_sustento_elegido(): void
    {
        $this->importar('000000201', [['SUS-BIEN', 2, 10]], '07')->assertCreated();

        $this->assertSame('07', Purchase::firstOrFail()->sustento_tributario);
    }

    public function test_la_importacion_por_xml_acepta_los_sustentos_nuevos_11_12_y_13(): void
    {
        foreach (['11' => '000000211', '12' => '000000212', '13' => '000000213'] as $sustento => $secuencial) {
            $sustento = (string) $sustento; // las claves numéricas del arreglo llegan como enteros
            $this->importar($secuencial, [['SUS-BIEN', 1, 10]], $sustento)->assertCreated();
            $this->assertSame($sustento, Purchase::where('numero', '001-001-'.$secuencial)->firstOrFail()->sustento_tributario);
        }
    }

    public function test_un_sustento_que_no_esta_en_el_catalogo_devuelve_422_y_no_importa_nada(): void
    {
        $this->importar('000000202', [['SUS-BIEN', 2, 10]], '99')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sustento_tributario']);

        $this->assertSame(0, Purchase::count());
    }

    public function test_sin_sustento_una_factura_con_bienes_con_stock_se_guarda_como_06_inventario(): void
    {
        $this->importar('000000203', [['SUS-BIEN', 2, 10]])->assertCreated();

        $this->assertSame('06', Purchase::firstOrFail()->sustento_tributario);
    }

    public function test_sin_sustento_una_factura_solo_de_servicios_se_guarda_como_01(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SUS-SERV', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);

        $this->importar('000000204', [['SUS-SERV', 1, 40]])->assertCreated();

        $this->assertSame('01', Purchase::firstOrFail()->sustento_tributario);
    }

    public function test_sin_sustento_una_factura_mixta_cuenta_como_inventario(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SUS-SERV', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);

        $this->importar('000000205', [['SUS-SERV', 1, 40], ['SUS-BIEN', 3, 5]])->assertCreated();

        $this->assertSame('06', Purchase::firstOrFail()->sustento_tributario);
    }

    public function test_el_servicio_que_guarda_el_xml_del_sri_tambien_respeta_o_deduce_el_sustento(): void
    {
        $almacen = app(StorePurchaseFromXml::class);

        $conBienes = $almacen->handle($this->company, $this->xmlCompra('000000221', [['SUS-BIEN', 2, 10]]));
        $this->assertSame('06', $conBienes->sustento_tributario);

        $elegido = $almacen->handle($this->company, $this->xmlCompra('000000222', [['SUS-BIEN', 2, 10]]), '02');
        $this->assertSame('02', $elegido->sustento_tributario);
    }

    // ------------------------------------------------------- compra manual

    public function test_la_compra_manual_guarda_sustento_y_tipo_de_comprobante_y_valida_el_catalogo(): void
    {
        $base = [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => '001-001-000000401', 'fecha_emision' => '2026-10-10',
            'items' => [['codigo_principal' => 'SUS-BIEN', 'cantidad' => 2, 'precio_unitario' => 10, 'tarifa' => 0]],
        ];

        $this->postJson('/api/purchases', $base + ['sustento_tributario' => '99'])
            ->assertStatus(422)->assertJsonValidationErrors(['sustento_tributario']);
        $this->postJson('/api/purchases', $base + ['tipo_comprobante' => 'nota_credito'])
            ->assertStatus(422)->assertJsonValidationErrors(['tipo_comprobante']);

        $this->postJson('/api/purchases', $base + ['sustento_tributario' => '12', 'tipo_comprobante' => 'nota_venta'])->assertCreated();
        $compra = Purchase::firstOrFail();
        $this->assertSame('12', $compra->sustento_tributario);
        $this->assertSame('nota_venta', $compra->tipo_comprobante);

        $xml = $this->ats();
        $this->assertSame(['12'], $this->valores($xml, 'compras/detalleCompras/codSustento'));
        $this->assertSame(['02'], $this->valores($xml, 'compras/detalleCompras/tipoComprobante'));
    }

    // ------------------------------------------------------------------- ATS

    public function test_el_ats_usa_el_sustento_y_el_tipo_de_comprobante_reales_de_cada_compra(): void
    {
        $this->compra('001-001-000000301', '07', 'compra');
        $this->compra('001-001-000000302', '02', 'liquidacion_compra');

        $xml = $this->ats();

        $this->assertSame(['07', '02'], $this->valores($xml, 'compras/detalleCompras/codSustento'));
        $this->assertSame(['01', '03'], $this->valores($xml, 'compras/detalleCompras/tipoComprobante'));
    }

    public function test_el_ats_conserva_los_valores_de_siempre_cuando_la_compra_no_trae_el_dato(): void
    {
        // Compra antigua: sin tipo de comprobante guardado y con el sustento por defecto (01)
        $this->compra('001-001-000000303', '01', null);

        $xml = $this->ats();

        $this->assertSame(['01'], $this->valores($xml, 'compras/detalleCompras/codSustento'));
        $this->assertSame(['01'], $this->valores($xml, 'compras/detalleCompras/tipoComprobante'));
    }

    // --------------------------------------------------------------- helpers

    private function importar(string $secuencial, array $lineas, ?string $sustento = null)
    {
        $datos = [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $this->xmlCompra($secuencial, $lineas)),
        ];
        if ($sustento !== null) {
            $datos['sustento_tributario'] = $sustento;
        }

        return $this->post('/api/purchases/import', $datos, ['Accept' => 'application/json']);
    }

    private function xmlCompra(string $secuencial, array $lineas): string
    {
        $detalles = '';
        $base = 0;
        foreach ($lineas as [$codigo, $cant, $precio]) {
            $sub = $cant * $precio;
            $base += $sub;
            $detalles .= "<detalle><codigoPrincipal>{$codigo}</codigoPrincipal><descripcion>{$codigo}</descripcion>"
                ."<cantidad>{$cant}</cantidad><precioUnitario>{$precio}</precioUnitario>"
                ."<impuestos><impuesto><codigo>2</codigo><tarifa>15</tarifa><baseImponible>{$sub}</baseImponible><valor>".($sub * 0.15).'</valor></impuesto></impuestos></detalle>';
        }
        $iva = $base * 0.15;
        $total = $base + $iva;
        $clave = str_pad($secuencial, 49, '1', STR_PAD_LEFT);

        return "<factura><infoTributaria><ruc>1790011223001</ruc><razonSocial>Proveedor Test</razonSocial>"
            ."<estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$secuencial}</secuencial><claveAcceso>{$clave}</claveAcceso></infoTributaria>"
            ."<infoFactura><fechaEmision>05/10/2026</fechaEmision><totalSinImpuestos>{$base}</totalSinImpuestos>"
            ."<totalConImpuestos><totalImpuesto><codigo>2</codigo><valor>{$iva}</valor></totalImpuesto></totalConImpuestos>"
            ."<importeTotal>{$total}</importeTotal></infoFactura><detalles>{$detalles}</detalles></factura>";
    }

    private function compra(string $numero, string $sustento, ?string $tipoComprobante): Purchase
    {
        return Purchase::create([
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => $numero, 'fecha_emision' => '2026-10-10', 'items' => [],
            'sustento_tributario' => $sustento, 'tipo_comprobante' => $tipoComprobante,
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'saldo_pendiente' => 115,
        ]);
    }

    private function ats(): \SimpleXMLElement
    {
        $respuesta = $this->get('/api/tax/ats/xml?company_id='.$this->company->id.'&anio=2026&mes=10')->assertOk();

        return simplexml_load_string($respuesta->getContent());
    }

    /** Texto de cada nodo que coincide con la ruta (desde la raíz <iva>), en el orden del documento. */
    private function valores(\SimpleXMLElement $xml, string $ruta): array
    {
        return array_map('strval', $xml->xpath('/iva/'.$ruta) ?: []);
    }
}
